<?php
declare(strict_types=1);

namespace Reut\Admin\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Reut\Router\DocsRegistry;
use Reut\Support\ProjectPath;

class DocsController
{
    public function getDocs(Request $request, Response $response): Response
    {
        try {
            ob_start(); // Prevent any output from config.php
            
            $projectRoot = ProjectPath::root();
            require $projectRoot . '/config.php';
            
            ob_end_clean();
            
            // Get all registered routes from DocsRegistry (actual routes)
            $endpoints = DocsRegistry::all();
            
            // Load model metadata (similar to core DocsController)
            $modelMetadata = $this->loadModelMetadata($config ?? []);
            
            // Group routes by model/group
            $groupedRoutes = [];
            foreach ($endpoints as $endpoint) {
                $group = $endpoint['group'] ?? 'Other';
                if (!isset($groupedRoutes[$group])) {
                    $groupedRoutes[$group] = [];
                }
                $groupedRoutes[$group][] = $endpoint;
            }

            // Map routes to models based on group/path
            $routesByModel = $this->mapRoutesToModels($endpoints, $modelMetadata);

            $response->getBody()->write(json_encode([
                'routes' => $endpoints,
                'groupedRoutes' => $groupedRoutes,
                'models' => $routesByModel,
                'total' => count($endpoints)
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'routes' => [],
                'error' => $e->getMessage()
            ], JSON_UNESCAPED_SLASHES));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }
    }

    private function loadModelMetadata(array $config): array
    {
        $projectRoot = ProjectPath::root();
        $modelsDir = $projectRoot . '/models';
        $modelsNamespace = 'Reut\\Models\\';
        $metadata = [];
        
        if (!is_dir($modelsDir)) {
            return $metadata;
        }
        
        $files = array_filter(glob($modelsDir . '/*Table.php') ?: [], fn($f) => str_ends_with($f, 'Table.php'));
        
        foreach ($files as $modelFile) {
            $modelName = str_replace('Table.php', '', basename($modelFile));
            $className = $modelsNamespace . basename($modelFile, '.php');
            
            if (!class_exists($className)) {
                require_once $modelFile;
            }
            
            if (class_exists($className)) {
                try {
                    $instance = new $className($config);
                    $tableName = $instance->tableName ?? strtolower($modelName);
                    
                    $metadata[$modelName] = [
                        'table' => $tableName,
                        'disabledRoutes' => $instance->disabledRoutes ?? [],
                        'requiresAuth' => $instance->requiresAuth ?? false,
                    ];
                } catch (\Throwable $e) {
                    // Skip models that can't be instantiated
                    continue;
                }
            }
        }
        
        return $metadata;
    }

    /**
     * Map actual routes from DocsRegistry to models
     */
    private function mapRoutesToModels(array $endpoints, array $modelMetadata): array
    {
        $routesByModel = [];
        
        foreach ($modelMetadata as $modelName => $meta) {
            $tableName = $meta['table'];
            $modelRoutes = [];
            
            // Find routes that match this model's table name
            foreach ($endpoints as $endpoint) {
                $path = $endpoint['path'] ?? '';
                // Check if route path contains the table name
                if (stripos($path, '/' . $tableName) !== false || 
                    stripos($path, '/' . strtolower($modelName)) !== false ||
                    ($endpoint['group'] ?? '') === $modelName) {
                    $modelRoutes[] = $endpoint;
                }
            }
            
            $routesByModel[$modelName] = [
                'table' => $tableName,
                'disabledRoutes' => $meta['disabledRoutes'],
                'requiresAuth' => $meta['requiresAuth'],
                'routes' => $modelRoutes // Only actual registered routes
            ];
        }
        
        return $routesByModel;
    }
}

