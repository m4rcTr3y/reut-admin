<?php
declare(strict_types=1);

namespace Reut\Admin\Services;

use Reut\DB\DataBase;
use Reut\DB\Exceptions\DatabaseConnectionException;
use Reut\Support\ProjectPath;

class MigrationService
{
    private $db;
    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->db = new DataBase($config);
        try {
            $this->db->connect();
        } catch (DatabaseConnectionException $e) {
            throw $e;
        }
    }

    public function getStatus(): array
    {
        $projectRoot = ProjectPath::root();
        // Config is already loaded, but ensure it's available
        if (!isset($GLOBALS['config'])) {
            require $projectRoot . '/config.php';
        }

        // Check if migrations table exists
        if (!$this->db->tableExists('migrations')) {
            return [
                'applied' => [],
                'pending' => [],
                'summary' => [
                    'total_applied' => 0,
                    'total_pending' => 0,
                    'total_batches' => 0
                ]
            ];
        }

        // Get applied migrations
        $applied = $this->db->sqlQuery("SELECT id, name, sql_text, batch, applied_at FROM migrations ORDER BY batch, id");

        // Get pending migrations by checking models
        $pending = $this->getPendingMigrations();

        return [
            'applied' => $applied ?? [],
            'pending' => $pending,
            'summary' => [
                'total_applied' => count($applied ?? []),
                'total_pending' => count($pending),
                'total_batches' => $this->getBatchCount()
            ]
        ];
    }

    /**
     * Get pending migrations by comparing models to database
     */
    private function getPendingMigrations(): array
    {
        $projectRoot = ProjectPath::root();
        $modelsDir = $projectRoot . '/models';
        $pending = [];

        if (!is_dir($modelsDir)) {
            return $pending;
        }

        // Autoload models
        spl_autoload_register(function ($class) use ($modelsDir) {
            $prefix = 'Reut\\Models\\';
            if (strpos($class, $prefix) === 0) {
                $relativeClass = substr($class, strlen($prefix));
                $file = $modelsDir . '/' . str_replace('\\', '/', $relativeClass) . '.php';
                if (file_exists($file)) {
                    require_once $file;
                }
            }
        });

        $modelFiles = array_filter(
            array_diff(scandir($modelsDir), ['.', '..']),
            fn($f) => str_ends_with($f, '.php')
        );

        foreach ($modelFiles as $fileName) {
            $className = 'Reut\\Models\\' . pathinfo($fileName, PATHINFO_FILENAME);
            if (!class_exists($className)) {
                continue;
            }

            try {
                $tableInstance = new $className($this->config);
                $tableName = $tableInstance->tableName;

                // Ensure database connection
                if (!$this->db->pdo) {
                    $this->db->connect();
                }

                // Extract model name from class (e.g., "UsersTable" -> "Users")
                $modelName = str_replace('Table', '', pathinfo($fileName, PATHINFO_FILENAME));

                // Check if table needs creation
                if (!$this->db->tableExists($tableName)) {
                    $pending[] = [
                        'type' => 'create',
                        'table' => $tableName,
                        'model' => $modelName,
                        'description' => "Create {$tableName} table",
                        'class' => $className
                    ];
                    continue;
                }

                // Check for missing columns
                try {
                    // Ensure connection for schema check
                    if (!$this->db->pdo) {
                        $this->db->connect();
                    }
                    
                    // Use the service's db connection to get schema, not the model instance
                    $dbColumns = $this->db->getTableSchema($tableName);
                    $modelColumns = array_keys($tableInstance->columns ?? []);
                    $missingColumns = array_diff($modelColumns, $dbColumns);

                    foreach ($missingColumns as $column) {
                        // Check if migration already exists
                        $existing = $this->db->sqlQuery(
                            "SELECT name FROM migrations WHERE name LIKE :pattern",
                            ['pattern' => "%add_{$column}_to_{$tableName}%"]
                        );
                        if (empty($existing)) {
                            $pending[] = [
                                'type' => 'add_column',
                                'table' => $tableName,
                                'model' => $modelName,
                                'column' => $column,
                                'description' => "Add {$column} to {$tableName}",
                                'class' => $className
                            ];
                        }
                    }
                } catch (\Exception $e) {
                    // Skip if can't get schema
                    error_log("Failed to check schema for {$tableName}: " . $e->getMessage());
                }
            } catch (\Exception $e) {
                // Skip models that can't be instantiated
                error_log("Failed to instantiate model {$className}: " . $e->getMessage());
                continue;
            }
        }

        return $pending;
    }

    public function apply(bool $dryRun = false): array
    {
        $projectRoot = ProjectPath::root();
        
        // Try to find Reut CLI binary first
        $reutBinary = null;
        $possiblePaths = [
            $projectRoot . '/vendor/bin/Reut',
            '/usr/local/bin/Reut',
            '/usr/bin/Reut',
            'Reut' // In PATH
        ];
        
        foreach ($possiblePaths as $path) {
            if ($path === 'Reut' || (file_exists($path) && is_executable($path))) {
                $reutBinary = $path;
                break;
            }
        }
        
        try {
            if ($reutBinary) {
                // Use Reut CLI binary
                $dryRunFlag = $dryRun ? '--dry-run' : '';
                $command = sprintf(
                    'cd %s && %s migrate %s 2>&1',
                    escapeshellarg($projectRoot),
                    escapeshellarg($reutBinary),
                    $dryRunFlag
                );
            } else {
                // Fallback to PHP script
                $migrateScript = $projectRoot . '/vendor/reut/cli/src/migrate.php';
                if (!file_exists($migrateScript)) {
                    $migrateScript = dirname(dirname(dirname(dirname(__DIR__)))) . '/Reut_CLI/src/migrate.php';
                }
                
                if (!file_exists($migrateScript)) {
                    return [
                        'success' => false,
                        'error' => 'Migration script not found. Please install Reut CLI.',
                        'migrations' => []
                    ];
                }
                
                $dryRunFlag = $dryRun ? '--dry-run' : '';
                $command = sprintf(
                    'cd %s && php %s %s 2>&1',
                    escapeshellarg($projectRoot),
                    escapeshellarg($migrateScript),
                    $dryRunFlag
                );
            }
            
            $output = [];
            $returnCode = 0;
            exec($command, $output, $returnCode);
            
            $outputText = implode("\n", $output);
            
            if ($returnCode !== 0) {
                return [
                    'success' => false,
                    'error' => 'Migration failed',
                    'output' => $outputText,
                    'migrations' => []
                ];
            }

            // Parse output to extract migration info
            $migrations = [];
            $lines = explode("\n", $outputText);
            foreach ($lines as $line) {
                if (preg_match('/Creating|Adding|Migration.*applied|table created|column.*added/i', $line)) {
                    $migrations[] = trim($line);
                }
            }

            return [
                'success' => true,
                'message' => $dryRun ? 'Dry run completed' : 'Migrations applied',
                'migrations' => $migrations,
                'output' => $outputText
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'migrations' => []
            ];
        }
    }

    public function rollback(?int $batch = null, ?string $migration = null, bool $dryRun = false): array
    {
        $projectRoot = ProjectPath::root();
        
        // Try to find Reut CLI binary first
        $reutBinary = null;
        $possiblePaths = [
            $projectRoot . '/vendor/bin/Reut',
            '/usr/local/bin/Reut',
            '/usr/bin/Reut',
            'Reut' // In PATH
        ];
        
        foreach ($possiblePaths as $path) {
            if ($path === 'Reut' || (file_exists($path) && is_executable($path))) {
                $reutBinary = $path;
                break;
            }
        }
        
        try {
            // Build rollback command
            $flags = [];
            if ($dryRun) {
                $flags[] = '--dry-run';
            }
            if ($batch !== null) {
                $flags[] = '--batch=' . $batch;
            }
            if ($migration !== null) {
                $flags[] = '--migration=' . escapeshellarg($migration);
            }
            
            if ($reutBinary) {
                // Use Reut CLI binary
                $command = sprintf(
                    'cd %s && %s rollback %s 2>&1',
                    escapeshellarg($projectRoot),
                    escapeshellarg($reutBinary),
                    implode(' ', $flags)
                );
            } else {
                // Fallback to PHP script
                $rollbackScript = $projectRoot . '/vendor/reut/cli/src/rollback.php';
                if (!file_exists($rollbackScript)) {
                    $rollbackScript = dirname(dirname(dirname(dirname(__DIR__)))) . '/Reut_CLI/src/rollback.php';
                }
                
                if (!file_exists($rollbackScript)) {
                    return [
                        'success' => false,
                        'error' => 'Rollback script not found. Please install Reut CLI.',
                        'rolled_back' => []
                    ];
                }
                
                $command = sprintf(
                    'cd %s && php %s %s 2>&1',
                    escapeshellarg($projectRoot),
                    escapeshellarg($rollbackScript),
                    implode(' ', $flags)
                );
            }
            
            $output = [];
            $returnCode = 0;
            exec($command, $output, $returnCode);
            
            $outputText = implode("\n", $output);
            
            if ($returnCode !== 0) {
                return [
                    'success' => false,
                    'error' => 'Rollback failed',
                    'output' => $outputText,
                    'rolled_back' => []
                ];
            }

            // Parse output
            $rolledBack = [];
            $lines = explode("\n", $outputText);
            foreach ($lines as $line) {
                if (preg_match('/Rolling back|Migration.*rolled back|rolled back successfully/i', $line)) {
                    $rolledBack[] = trim($line);
                }
            }

            return [
                'success' => true,
                'message' => $dryRun ? 'Dry run completed' : 'Migrations rolled back',
                'rolled_back' => $rolledBack,
                'output' => $outputText
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'rolled_back' => []
            ];
        }
    }

    /**
     * Delete a migration record (does not rollback, just removes from history)
     */
    public function deleteMigration(int $migrationId): array
    {
        try {
            if (!$this->db->tableExists('migrations')) {
                return [
                    'success' => false,
                    'error' => 'Migrations table does not exist'
                ];
            }

            // Get migration info before deletion
            $migration = $this->db->sqlQuery(
                "SELECT * FROM migrations WHERE id = :id",
                ['id' => $migrationId]
            );

            if (empty($migration)) {
                return [
                    'success' => false,
                    'error' => 'Migration not found'
                ];
            }

            // Delete migration record
            $this->db->execute(
                "DELETE FROM migrations WHERE id = :id",
                ['id' => $migrationId]
            );

            return [
                'success' => true,
                'message' => 'Migration deleted successfully',
                'migration' => $migration[0]
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function getHistory(): array
    {
        if (!$this->db->tableExists('migrations')) {
            return [];
        }

        $migrations = $this->db->sqlQuery(
            "SELECT id, name, sql_text, batch, applied_at FROM migrations ORDER BY batch DESC, id DESC"
        );

        return $migrations ?? [];
    }

    private function getBatchCount(): int
    {
        $result = $this->db->sqlQuery("SELECT MAX(batch) as max_batch FROM migrations");
        if ($result && isset($result[0]['max_batch'])) {
            return (int)$result[0]['max_batch'];
        }
        return 0;
    }
}

