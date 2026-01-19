<?php
declare(strict_types=1);

namespace Reut\Admin\Commands;

use Reut\Admin\Models\AdminUser;
use Reut\Admin\Models\AdminLog;
use Reut\Admin\Models\AdminSession;
use Reut\Admin\Models\AdminAuditLog;
use Reut\Admin\Models\LoginAttempt;
use Reut\Admin\Models\ApiKey;
use Reut\Admin\Models\FunctionModel;
use Reut\DB\DataBase;
use Reut\Support\ProjectPath;

/**
 * Admin Setup Command
 * Sets up admin database tables and creates initial admin user
 */
class AdminSetupCommand
{
    private $config;
    private $projectRoot;

    public function __construct()
    {
        $this->projectRoot = ProjectPath::root();
        require $this->projectRoot . '/config.php';
        $this->config = $config ?? [];
    }

    /**
     * Execute the setup command
     */
    public function execute(): int
    {
        echo "\nReut Admin Dashboard Setup\n";
        echo "============================\n\n";

        // Check if admin package is installed
        if (!class_exists(AdminUser::class)) {
            echo "[ERROR] Admin package not found.\n\n";
            echo "Please install the admin package first:\n";
            echo "  composer require m4rc/reut-admin\n\n";
            echo "Then run this setup command again.\n";
            return 1;
        }

        try {
            // Step 1: Create admin_users table
            echo "Step 1: Creating admin_users table...\n";
            $this->createAdminUsersTable();
            echo "[OK] admin_users table created successfully\n\n";

            // Step 2: Create admin_logs table
            echo "Step 2: Creating admin_logs table...\n";
            $this->createAdminLogsTable();
            echo "[OK] admin_logs table created successfully\n\n";

            // Step 2.1: Create admin_sessions table
            echo "Step 2.1: Creating admin_sessions table...\n";
            $this->createAdminSessionsTable();
            echo "[OK] admin_sessions table created successfully\n\n";

            // Step 2.2: Create admin_audit_logs table
            echo "Step 2.2: Creating admin_audit_logs table...\n";
            $this->createAdminAuditLogsTable();
            echo "[OK] admin_audit_logs table created successfully\n\n";

            // Step 2.3: Create admin_login_attempts table
            echo "Step 2.3: Creating admin_login_attempts table...\n";
            $this->createAdminLoginAttemptsTable();
            echo "[OK] admin_login_attempts table created successfully\n\n";

            // Step 2.4: Create api_keys table
            echo "Step 2.4: Creating api_keys table...\n";
            $this->createApiKeysTable();
            echo "[OK] api_keys table created successfully\n\n";

            // Step 2.5: Create functions table
            echo "Step 2.5: Creating functions table...\n";
            $this->createFunctionsTable();
            echo "[OK] functions table created successfully\n\n";

            // Step 3: Prompt for admin user credentials
            echo "Step 3: Create admin user\n";
            echo "----------------------------\n";
            $this->createAdminUser();

            // Step 4: Add log retention to .env
            echo "\nStep 4: Configuring log retention...\n";
            $this->addLogRetentionToEnv();

            // Step 5: Add admin security configuration to .env
            echo "\nStep 5: Configuring admin security settings...\n";
            $this->addAdminSecurityConfigToEnv();

            // Step 6: Add functions and logging configuration to .env
            echo "\nStep 6: Configuring functions and project logging...\n";
            $this->addFunctionsAndLoggingConfigToEnv();

            // Step 7: Create functions directory
            echo "\nStep 7: Creating functions directory...\n";
            $this->createFunctionsDirectory();

            echo "\n[OK] Admin dashboard setup completed successfully!\n";
            echo "\nYou can now access the admin dashboard at: /admin\n";
            return 0;
        } catch (\Exception $e) {
            echo "\n[ERROR] " . $e->getMessage() . "\n";
            if (isset($e->getTrace()[0])) {
                echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n";
            }
            return 1;
        }
    }

    /**
     * Create admin_users table
     */
    private function createAdminUsersTable(): void
    {
        $adminUser = new AdminUser($this->config);
        $adminUser->connect();
        
        // Check if table already exists
        if ($adminUser->tableExists('admin_users')) {
            echo "[WARNING] admin_users table already exists, skipping...\n";
            return;
        }

        // Create the table
        if (!$adminUser->createTable()) {
            throw new \Exception("Failed to create admin_users table");
        }
    }

    /**
     * Create admin_logs table
     */
    private function createAdminLogsTable(): void
    {
        $adminLog = new AdminLog($this->config);
        $adminLog->connect();
        
        // Check if table already exists
        if ($adminLog->tableExists('admin_logs')) {
            echo "[WARNING] admin_logs table already exists, skipping...\n";
            return;
        }

        // Create the table
        if (!$adminLog->createTable()) {
            throw new \Exception("Failed to create admin_logs table");
        }
    }

    /**
     * Create admin_sessions table
     */
    private function createAdminSessionsTable(): void
    {
        $adminSession = new AdminSession($this->config);
        $adminSession->connect();
        
        // Check if table already exists
        if ($adminSession->tableExists('admin_sessions')) {
            echo "[WARNING] admin_sessions table already exists, skipping...\n";
            return;
        }

        // Create the table
        if (!$adminSession->createTable()) {
            throw new \Exception("Failed to create admin_sessions table");
        }
    }

    /**
     * Create admin_audit_logs table
     */
    private function createAdminAuditLogsTable(): void
    {
        $adminAuditLog = new AdminAuditLog($this->config);
        $adminAuditLog->connect();
        
        // Check if table already exists
        if ($adminAuditLog->tableExists('admin_audit_logs')) {
            echo "[WARNING] admin_audit_logs table already exists, skipping...\n";
            return;
        }

        // Create the table
        if (!$adminAuditLog->createTable()) {
            throw new \Exception("Failed to create admin_audit_logs table");
        }
    }

    /**
     * Create admin_login_attempts table
     */
    private function createAdminLoginAttemptsTable(): void
    {
        $loginAttempt = new LoginAttempt($this->config);
        $loginAttempt->connect();
        
        // Check if table already exists
        if ($loginAttempt->tableExists('admin_login_attempts')) {
            echo "[WARNING] admin_login_attempts table already exists, skipping...\n";
            return;
        }

        // Create the table
        if (!$loginAttempt->createTable()) {
            throw new \Exception("Failed to create admin_login_attempts table");
        }
    }

    /**
     * Create api_keys table
     */
    private function createApiKeysTable(): void
    {
        $apiKey = new ApiKey($this->config);
        $apiKey->connect();
        
        // Check if table already exists
        if ($apiKey->tableExists('api_keys')) {
            echo "[WARNING] api_keys table already exists, skipping...\n";
            return;
        }

        // Create the table
        try {
            $result = $apiKey->createTable();
            if (!$result) {
                throw new \Exception("createTable() returned false");
            }
            echo "   [OK] Created api_keys table structure\n";
        } catch (\Exception $e) {
            throw new \Exception("Failed to create api_keys table: " . $e->getMessage());
        }

        // Add unique constraint on `key` column (reserved keyword, needs backticks)
        try {
            $apiKey->sqlQuery(
                "ALTER TABLE `api_keys` ADD UNIQUE INDEX `idx_key` (`key`)",
                []
            );
            echo "   [OK] Added unique constraint on `key` column\n";
        } catch (\Exception $e) {
            // Check if index already exists (MySQL error 1061 or 1062)
            $errorMsg = $e->getMessage();
            if (strpos($errorMsg, 'Duplicate key name') !== false || 
                strpos($errorMsg, 'already exists') !== false ||
                strpos($errorMsg, '1061') !== false) {
                echo "   [WARNING] Unique index on `key` column already exists, skipping...\n";
            } else {
                echo "   [WARNING] Could not add unique constraint: " . $errorMsg . "\n";
            }
        }

        // Add index on is_active for better query performance
        try {
            $apiKey->sqlQuery(
                "ALTER TABLE `api_keys` ADD INDEX `idx_is_active` (`is_active`)",
                []
            );
            echo "   [OK] Added index on `is_active` column\n";
        } catch (\Exception $e) {
            // Check if index already exists
            $errorMsg = $e->getMessage();
            if (strpos($errorMsg, 'Duplicate key name') !== false || 
                strpos($errorMsg, 'already exists') !== false ||
                strpos($errorMsg, '1061') !== false) {
                echo "   [WARNING] Index on `is_active` column already exists, skipping...\n";
            } else {
                echo "   [WARNING] Could not add index on is_active: " . $errorMsg . "\n";
            }
        }
    }

    /**
     * Create functions table
     */
    private function createFunctionsTable(): void
    {
        $function = new FunctionModel($this->config);
        $function->connect();
        
        // Check if table already exists
        if ($function->tableExists('functions')) {
            echo "[WARNING] functions table already exists, skipping...\n";
            return;
        }

        // Create the table
        if (!$function->createTable()) {
            throw new \Exception("Failed to create functions table");
        }
    }

    /**
     * Create admin user interactively
     */
    private function createAdminUser(): void
    {
        $adminUser = new AdminUser($this->config);
        $adminUser->connect();

        // Check if admin users already exist
        $existing = $adminUser->sqlQuery("SELECT COUNT(*) as count FROM admin_users");
        $userCount = $existing[0]['count'] ?? 0;

        if ($userCount > 0) {
            echo "[WARNING] Admin users already exist ({$userCount} user(s)).\n";
            $create = $this->prompt("Do you want to create another admin user? (y/n): ");
            if (strtolower(trim($create)) !== 'y') {
                echo "Skipping admin user creation.\n";
                return;
            }
        }

        // Prompt for username
        $username = '';
        while (empty($username)) {
            $username = trim($this->prompt("Username: "));
            if (empty($username)) {
                echo "[ERROR] Username cannot be empty.\n";
            } elseif (strlen($username) < 3) {
                echo "[ERROR] Username must be at least 3 characters.\n";
                $username = '';
            } else {
                // Check if username already exists
                $existing = $adminUser->findOne(['username' => $username]);
                if ($existing && $existing->results) {
                    echo "[ERROR] Username already exists. Please choose another.\n";
                    $username = '';
                }
            }
        }

        // Prompt for email
        $email = '';
        while (empty($email)) {
            $email = trim($this->prompt("Email: "));
            if (empty($email)) {
                echo "[ERROR] Email cannot be empty.\n";
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                echo "[ERROR] Invalid email format.\n";
                $email = '';
            } else {
                // Check if email already exists
                $existing = $adminUser->findOne(['email' => $email]);
                if ($existing && $existing->results) {
                    echo "[ERROR] Email already exists. Please choose another.\n";
                    $email = '';
                }
            }
        }

        // Prompt for password
        $password = '';
        while (empty($password)) {
            $password = $this->promptPassword("Password: ");
            if (empty($password)) {
                echo "[ERROR] Password cannot be empty.\n";
            } elseif (strlen($password) < 8) {
                echo "[ERROR] Password must be at least 8 characters.\n";
                $password = '';
            } else {
                $confirmPassword = $this->promptPassword("Confirm Password: ");
                if ($password !== $confirmPassword) {
                    echo "[ERROR] Passwords do not match.\n";
                    $password = '';
                }
            }
        }

        // Prompt for role (optional)
        $role = trim($this->prompt("Role (default: admin): "));
        if (empty($role)) {
            $role = 'admin';
        }

        // Create the admin user
        echo "\nCreating admin user...\n";
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        
        try {
            $result = $adminUser->addOne([
                'username' => $username,
                'email' => $email,
                'password' => $hashedPassword,
                'role' => $role,
            ]);

            if ($result === true || $result === 1) {
                echo "[OK] Admin user '{$username}' created successfully!\n";
            } else {
                // Check if user was actually created (sometimes addOne returns different values)
                $check = $adminUser->findOne(['username' => $username]);
                if ($check && $check->results) {
                    echo "[OK] Admin user '{$username}' created successfully!\n";
                } else {
                    throw new \Exception("Failed to create admin user: " . (is_string($result) ? $result : 'Unknown error'));
                }
            }
        } catch (\Exception $e) {
            throw new \Exception("Failed to create admin user: " . $e->getMessage());
        }
    }

    /**
     * Prompt for user input
     */
    private function prompt(string $message): string
    {
        echo $message;
        $handle = fopen("php://stdin", "r");
        $line = fgets($handle);
        fclose($handle);
        return $line !== false ? trim($line) : '';
    }

    /**
     * Prompt for password (hidden input)
     */
    private function promptPassword(string $message): string
    {
        echo $message;
        flush();
        
        // Try to hide password input
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows
            $exe = __DIR__ . '/../../bin/hideinput.exe';
            if (file_exists($exe)) {
                $value = rtrim(shell_exec($exe) ?: '');
            } else {
                // Fallback: read without hiding (Windows)
                $handle = fopen("php://stdin", "r");
                $line = fgets($handle);
                fclose($handle);
                $value = $line !== false ? trim($line) : '';
            }
        } else {
            // Unix/Linux/Mac
            // Check if we're in an interactive terminal
            $isInteractive = function_exists('posix_isatty') && posix_isatty(STDIN);
            
            if ($isInteractive) {
                // Save current terminal settings
                $sttyMode = @shell_exec('stty -g 2>/dev/null');
                
                // Disable echo
                @system('stty -echo 2>/dev/null');
                
                // Read password
                $handle = fopen("php://stdin", "r");
                if ($handle === false) {
                    // Fallback if fopen fails
                    $value = '';
                } else {
                    $line = fgets($handle);
                    fclose($handle);
                    $value = $line !== false ? trim($line) : '';
                }
                
                // Always restore terminal settings
                if ($sttyMode) {
                    @system("stty {$sttyMode} 2>/dev/null");
                } else {
                    @system('stty echo 2>/dev/null');
                }
                
                echo "\n";
            } else {
                // Not an interactive terminal or posix functions not available, read normally
                $handle = fopen("php://stdin", "r");
                if ($handle === false) {
                    $value = '';
                } else {
                    $line = fgets($handle);
                    fclose($handle);
                    $value = $line !== false ? trim($line) : '';
                }
            }
        }
        
        return $value;
    }

    /**
     * Add log retention configuration to .env file
     */
    private function addLogRetentionToEnv(): void
    {
        $envPath = $this->projectRoot . '/.env';
        
        // Check if ADMIN_LOG_RETENTION_DAYS already exists
        if (file_exists($envPath)) {
            $envContent = file_get_contents($envPath);
            if (strpos($envContent, 'ADMIN_LOG_RETENTION_DAYS') !== false) {
                echo "[WARNING] ADMIN_LOG_RETENTION_DAYS already exists in .env, skipping...\n";
                return;
            }
        }

        // Add log retention config
        $logRetentionConfig = "\n# Admin Dashboard Log Retention (days)\nADMIN_LOG_RETENTION_DAYS=30\n";
        
        if (file_exists($envPath)) {
            // Append to existing .env
            file_put_contents($envPath, $logRetentionConfig, FILE_APPEND);
            echo "[OK] Added ADMIN_LOG_RETENTION_DAYS=30 to .env\n";
        } else {
            // Create new .env file
            file_put_contents($envPath, $logRetentionConfig);
            echo "[OK] Created .env file with ADMIN_LOG_RETENTION_DAYS=30\n";
        }
    }

    /**
     * Add admin security configuration to .env file
     */
    private function addAdminSecurityConfigToEnv(): void
    {
        $envPath = $this->projectRoot . '/.env';
        $envContent = file_exists($envPath) ? file_get_contents($envPath) : '';
        
        $securityConfig = "\n# Admin Dashboard Security Configuration\n";
        $added = false;
        
        // HTTPS Only (optional, default: false)
        if (strpos($envContent, 'ADMIN_HTTPS_ONLY') === false) {
            $securityConfig .= "ADMIN_HTTPS_ONLY=false\n";
            $added = true;
        }
        
        // Rate Limiting
        if (strpos($envContent, 'ADMIN_RATE_LIMIT_ENABLED') === false) {
            $securityConfig .= "ADMIN_RATE_LIMIT_ENABLED=true\n";
            $added = true;
        }
        if (strpos($envContent, 'ADMIN_RATE_LIMIT_MAX_REQUESTS') === false) {
            $securityConfig .= "ADMIN_RATE_LIMIT_MAX_REQUESTS=60\n";
            $added = true;
        }
        if (strpos($envContent, 'ADMIN_RATE_LIMIT_WINDOW_SECONDS') === false) {
            $securityConfig .= "ADMIN_RATE_LIMIT_WINDOW_SECONDS=60\n";
            $added = true;
        }
        
        // Request Size Limit
        if (strpos($envContent, 'ADMIN_REQUEST_SIZE_LIMIT_ENABLED') === false) {
            $securityConfig .= "ADMIN_REQUEST_SIZE_LIMIT_ENABLED=true\n";
            $added = true;
        }
        if (strpos($envContent, 'ADMIN_REQUEST_SIZE_LIMIT_MB') === false) {
            $securityConfig .= "ADMIN_REQUEST_SIZE_LIMIT_MB=10\n";
            $added = true;
        }
        
        if ($added) {
            if (file_exists($envPath)) {
                file_put_contents($envPath, $securityConfig, FILE_APPEND);
                echo "[OK] Added admin security configuration to .env\n";
            } else {
                file_put_contents($envPath, $securityConfig);
                echo "[OK] Created .env file with admin security configuration\n";
            }
        } else {
            echo "[WARNING] All admin security variables already exist in .env, skipping...\n";
        }
    }

    /**
     * Add functions and project logging configuration to .env file
     */
    private function addFunctionsAndLoggingConfigToEnv(): void
    {
        $envPath = $this->projectRoot . '/.env';
        $envContent = file_exists($envPath) ? file_get_contents($envPath) : '';
        
        $config = "\n# Functions and Project Logging Configuration\n";
        $added = false;
        
        // Functions feature
        if (strpos($envContent, 'FUNCTIONS_ENABLED') === false) {
            $config .= "FUNCTIONS_ENABLED=true\n";
            $added = true;
        }
        
        // Project logging
        if (strpos($envContent, 'PROJECT_LOGGING_ENABLED') === false) {
            $config .= "PROJECT_LOGGING_ENABLED=true\n";
            $added = true;
        }
        if (strpos($envContent, 'PROJECT_LOGGING_METHODS') === false) {
            $config .= "PROJECT_LOGGING_METHODS=GET,POST,PUT,DELETE,PATCH\n";
            $added = true;
        }
        
        // API Key Authentication
        if (strpos($envContent, 'API_KEY_AUTH_ENABLED') === false) {
            $config .= "API_KEY_AUTH_ENABLED=true\n";
            $added = true;
        }
        
        if ($added) {
            if (file_exists($envPath)) {
                file_put_contents($envPath, $config, FILE_APPEND);
                echo "[OK] Added functions and project logging configuration to .env\n";
            } else {
                file_put_contents($envPath, $config);
                echo "[OK] Created .env file with functions and project logging configuration\n";
            }
        } else {
            echo "[WARNING] All functions and logging variables already exist in .env, skipping...\n";
        }
    }

    /**
     * Create functions directory and example function
     */
    private function createFunctionsDirectory(): void
    {
        $functionsDir = $this->projectRoot . '/functions';
        
        if (!is_dir($functionsDir)) {
            mkdir($functionsDir, 0755, true);
            echo "[OK] Created functions directory\n";
        } else {
            echo "[WARNING] Functions directory already exists, skipping...\n";
        }

        // Create example function if it doesn't exist
        $exampleFile = $functionsDir . '/example.php';
        if (!file_exists($exampleFile)) {
            $exampleCode = <<<'PHP'
<?php
/**
 * Example Function
 * This is a sample function to demonstrate the functions feature.
 * 
 * @param Request $request - Full PSR-7 Request object
 * @param array $params - Parsed parameters (query + body)
 * @return array - Response data (will be JSON encoded)
 */
return function($request, $params) {
    $name = $params['name'] ?? 'World';
    $message = $params['message'] ?? 'Hello';
    
    return [
        'success' => true,
        'message' => "{$message}, {$name}!",
        'timestamp' => date('Y-m-d H:i:s'),
        'received_params' => $params,
        'request_method' => $request->getMethod(),
        'request_path' => $request->getUri()->getPath()
    ];
};
PHP;
            file_put_contents($exampleFile, $exampleCode);
            echo "[OK] Created example function\n";
        } else {
            echo "[WARNING] Example function already exists, skipping...\n";
        }
    }
}

