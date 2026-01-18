<?php
declare(strict_types=1);

namespace Reut\Admin\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Reut\Admin\Services\ErrorSanitizer;
use Reut\Router\SchemaController as CoreSchemaController;
use Reut\Support\ProjectPath;
use Slim\Psr7\Stream;

class SchemaController
{
    public function getSchema(Request $request, Response $response): Response
    {
        // Start output buffering to catch any accidental output
        ob_start();
        
        try {
            $projectRoot = ProjectPath::root();
            $configPath = $projectRoot . '/config.php';
            
            if (!file_exists($configPath)) {
                ob_end_clean();
                $response->getBody()->write(json_encode(['error' => ErrorSanitizer::getGenericMessage('not_found')]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
            }
            
            // Capture any output from config.php
            require $configPath;
            $configOutput = ob_get_clean();
            
            if (!isset($config) || !is_array($config)) {
                $response->getBody()->write(json_encode(['error' => ErrorSanitizer::getGenericMessage('validation')]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
            }
            
            // Start buffering again for model requires
            ob_start();
            
            $modelsDir = $projectRoot . '/models';
            $modelsNamespace = 'Reut\\Models\\';
            
            $tables = [];
            $errors = [];
            
            if (is_dir($modelsDir)) {
                $files = array_filter(glob($modelsDir . '/*.php') ?: [], fn($f) => str_ends_with($f, '.php'));
                foreach ($files as $modelFile) {
                    $metadata = $this->loadModelMetadata($modelFile, $modelsNamespace, $config, $errors);
                    if ($metadata !== null) {
                        $tables[] = $metadata;
                    }
                }
                usort($tables, fn($a, $b) => $b['filemtime'] <=> $a['filemtime']);
            } else {
                $errors[] = "Models directory not found at {$modelsDir}";
            }
            
            // Clean any output from model files
            ob_end_clean();
            
            $json = json_encode([
                'tables' => $tables,
                'errors' => $errors,
                'total' => count($tables),
                'generated' => date('Y-m-d H:i:s')
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            
            if ($json === false) {
                $errorJson = json_encode([
                    'error' => 'Failed to encode JSON',
                    'json_error' => json_last_error_msg()
                ], JSON_UNESCAPED_SLASHES);
                $response->getBody()->rewind();
                $response->getBody()->write($errorJson);
                return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus(500);
            }
            
            // Create a completely fresh response body to avoid any contamination
            $body = new Stream(fopen('php://temp', 'r+'));
            $body->write($json);
            
            return $response
                ->withBody($body)
                ->withHeader('Content-Type', 'application/json; charset=utf-8')
                ->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
                ->withHeader('Content-Length', (string)strlen($json));
        } catch (\Throwable $e) {
            ob_end_clean();
            $response->getBody()->write(json_encode([
                'error' => ErrorSanitizer::sanitize($e, 'getSchema')
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }
    
    private function loadModelMetadata(string $filePath, string $modelsNamespace, array $config, array &$errors): ?array
    {
        // Capture any output from model file
        ob_start();
        
        try {
            $className = $modelsNamespace . pathinfo($filePath, PATHINFO_FILENAME);
            $mtime = filemtime($filePath);
            
            if (!class_exists($className)) {
                require_once $filePath;
            }
            
            // Clean any output from require
            ob_end_clean();
            
            if (!class_exists($className)) {
                $errors[] = "Unable to load model class {$className}.";
                return null;
            }
            
            // Buffer output during instantiation
            ob_start();
            
            try {
                $instance = new $className($config);
            } finally {
                ob_end_clean();
            }
        } catch (\Throwable $e) {
            ob_end_clean();
            $errors[] = "Failed to instantiate {$className}: " . $e->getMessage();
            return null;
        }
        
        $columns = [];
        
        // Buffer output during metadata extraction
        ob_start();
        try {
            $foreignKeys = method_exists($instance, 'getForeignKeys') ? $instance->getForeignKeys() : [];
            
            foreach ($instance->columns ?? [] as $name => $definition) {
                ob_start();
                try {
                    $definitionSql = method_exists($definition, 'getSql') ? $definition->getSql() : 'N/A';
                    $isPrimary = method_exists($definition, 'isPrimaryKey') ? $definition->isPrimaryKey() : false;
                } finally {
                    ob_end_clean();
                }
                
                $foreignKey = null;
                foreach ($foreignKeys as $fk) {
                    if ($fk['column'] === $name) {
                        $foreignKey = $fk;
                        break;
                    }
                }
                
                $columns[] = [
                    'name' => $name,
                    'definition' => $definitionSql,
                    'isPrimary' => $isPrimary,
                    'foreignKey' => $foreignKey
                ];
            }
        } finally {
            ob_end_clean();
        }
        
        // Buffer output during property access
        ob_start();
        try {
            $hasRelationships = method_exists($instance, 'hasRelationships') ? $instance->hasRelationships() : (bool)($instance->relationships ?? false);
            $relationshipCount = method_exists($instance, 'getRelationshipCount') ? $instance->getRelationshipCount() : (int)($instance->relationships ?? 0);
            
            $traits = class_uses($className) ?: [];
            $hasDeletedAt = in_array('deleted_at', array_column($columns, 'name'));
            $hasTimestamps = in_array('created_at', array_column($columns, 'name')) && in_array('updated_at', array_column($columns, 'name'));
            
            $disabledRoutes = $instance->disabledRoutes ?? [];
            $requiresAuth = $instance->requiresAuth ?? false;
        } finally {
            ob_end_clean();
        }
        
        return [
            'class' => $className,
            'table' => $instance->tableName ?? pathinfo($filePath, PATHINFO_FILENAME),
            'columns' => $columns,
            'hasRelationships' => $hasRelationships,
            'relationshipCount' => $relationshipCount,
            'traits' => $traits,
            'hasDeletedAt' => $hasDeletedAt,
            'hasTimestamps' => $hasTimestamps,
            'disabledRoutes' => $disabledRoutes,
            'requiresAuth' => $requiresAuth,
            'filemtime' => $mtime,
            'modifiedAgo' => time() - $mtime,
            'filePath' => $filePath
        ];
    }
}

