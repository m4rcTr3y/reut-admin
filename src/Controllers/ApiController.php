<?php
declare(strict_types=1);

namespace Reut\Admin\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Reut\Router\DocsRegistry;
use Reut\Support\ProjectPath;

class ApiController
{
    private $config;

    public function __construct()
    {
        $projectRoot = ProjectPath::root();
        require $projectRoot . '/config.php';
        $this->config = $config ?? [];
    }

    public function getRoutes(Request $request, Response $response): Response
    {
        try {
            // Get all registered routes from DocsRegistry
            $endpoints = DocsRegistry::all();
            
            // Load model metadata to get requiresAuth info
            $projectRoot = ProjectPath::root();
            require $projectRoot . '/config.php';
            $modelMetadata = $this->loadModelMetadata($config ?? []);
            
            // Enhance endpoints with model auth requirements
            foreach ($endpoints as &$endpoint) {
                // If requiresAuth is not explicitly set, check model metadata
                if (!isset($endpoint['requiresAuth']) || $endpoint['requiresAuth'] === false) {
                    $group = $endpoint['group'] ?? '';
                    if (isset($modelMetadata[$group])) {
                        $endpoint['requiresAuth'] = $modelMetadata[$group]['requiresAuth'];
                    }
                }
            }
            unset($endpoint); // Break reference
            
            // Group endpoints by group/prefix
            $groupedEndpoints = [];
            foreach ($endpoints as $endpoint) {
                $group = $endpoint['group'] ?? 'default';
                if (!isset($groupedEndpoints[$group])) {
                    $groupedEndpoints[$group] = [];
                }
                $groupedEndpoints[$group][] = $endpoint;
            }

            // Also try to discover routes from routes.php file
            $routesFile = $projectRoot . '/routers/routes.php';
            $discoveredRoutes = [];
            
            if (file_exists($routesFile)) {
                $discoveredRoutes = $this->discoverRoutesFromFile($routesFile);
            }

            $response->getBody()->write(json_encode([
                'endpoints' => $endpoints,
                'groupedEndpoints' => $groupedEndpoints,
                'discoveredRoutes' => $discoveredRoutes,
                'modelMetadata' => $modelMetadata,
                'total' => count($endpoints)
            ], JSON_UNESCAPED_SLASHES));
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'endpoints' => [],
                'groupedEndpoints' => [],
                'error' => $e->getMessage()
            ], JSON_UNESCAPED_SLASHES));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }
    }

    public function getRouteDetails(Request $request, Response $response, array $args): Response
    {
        try {
            $method = strtoupper($args['method'] ?? '');
            $path = $args['path'] ?? '';
            
            $endpoints = DocsRegistry::all();
            $matchedEndpoint = null;
            
            foreach ($endpoints as $endpoint) {
                if (strtoupper($endpoint['method']) === $method && $endpoint['path'] === $path) {
                    $matchedEndpoint = $endpoint;
                    break;
                }
            }

            if (!$matchedEndpoint) {
                $response->getBody()->write(json_encode([
                    'error' => 'Route not found'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            // Get additional route information
            $routeInfo = [
                'endpoint' => $matchedEndpoint,
                'requiresAuth' => $matchedEndpoint['requiresAuth'] ?? false,
                'method' => $matchedEndpoint['method'] ?? 'GET',
                'path' => $matchedEndpoint['path'] ?? '',
                'description' => $matchedEndpoint['description'] ?? '',
                'group' => $matchedEndpoint['group'] ?? ''
            ];

            $response->getBody()->write(json_encode([
                'route' => $routeInfo
            ], JSON_UNESCAPED_SLASHES));
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'error' => $e->getMessage()
            ], JSON_UNESCAPED_SLASHES));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }
    }

    public function generateApiDocs(Request $request, Response $response): Response
    {
        try {
            $endpoints = DocsRegistry::all();
            
            // Group by group/prefix
            $grouped = [];
            foreach ($endpoints as $endpoint) {
                $group = $endpoint['group'] ?? 'default';
                if (!isset($grouped[$group])) {
                    $grouped[$group] = [];
                }
                $grouped[$group][] = $endpoint;
            }

            // Load model metadata to get requiresAuth info
            $projectRoot = ProjectPath::root();
            $modelsDir = $projectRoot . '/models';
            $modelsNamespace = 'Reut\\Models\\';
            $modelMetadata = [];
            
            if (is_dir($modelsDir)) {
                $files = array_filter(glob($modelsDir . '/*Table.php') ?: [], fn($f) => str_ends_with($f, 'Table.php'));
                foreach ($files as $modelFile) {
                    $modelName = str_replace('Table.php', '', basename($modelFile));
                    $className = $modelsNamespace . basename($modelFile, '.php');
                    
                    if (class_exists($className)) {
                        try {
                            require $projectRoot . '/config.php';
                            $instance = new $className($config ?? []);
                            $modelMetadata[$modelName] = [
                                'requiresAuth' => $instance->requiresAuth ?? false,
                                'table' => $instance->tableName ?? strtolower($modelName)
                            ];
                        } catch (\Throwable $e) {
                            // Skip models that can't be instantiated
                        }
                    }
                }
            }

            // Generate OpenAPI/Swagger-like documentation
            $docs = [
                'openapi' => '3.0.0',
                'info' => [
                    'title' => 'Reut API Documentation',
                    'version' => '1.0.0',
                    'description' => 'Auto-generated API documentation'
                ],
                'paths' => []
            ];

            foreach ($endpoints as $endpoint) {
                $path = $endpoint['path'] ?? '/';
                $method = strtolower($endpoint['method'] ?? 'get');
                
                // Check if this route requires auth from endpoint or model metadata
                $requiresAuth = $endpoint['requiresAuth'] ?? false;
                
                // If not set in endpoint, check model metadata
                if (!$requiresAuth) {
                    $group = $endpoint['group'] ?? '';
                    if (isset($modelMetadata[$group])) {
                        $requiresAuth = $modelMetadata[$group]['requiresAuth'];
                    }
                }
                
                if (!isset($docs['paths'][$path])) {
                    $docs['paths'][$path] = [];
                }
                
                $docs['paths'][$path][$method] = [
                    'summary' => $endpoint['description'] ?? '',
                    'description' => $endpoint['description'] ?? '',
                    'security' => $requiresAuth ? [['bearerAuth' => []]] : [],
                    'requiresAuth' => $requiresAuth
                ];
            }

            $response->getBody()->write(json_encode($docs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
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
            
            if (class_exists($className)) {
                try {
                    $instance = new $className($config);
                    $metadata[$modelName] = [
                        'requiresAuth' => $instance->requiresAuth ?? false,
                        'table' => $instance->tableName ?? strtolower($modelName),
                        'disabledRoutes' => $instance->disabledRoutes ?? []
                    ];
                } catch (\Throwable $e) {
                    // Skip models that can't be instantiated
                    continue;
                }
            }
        }
        
        return $metadata;
    }

    private function discoverRoutesFromFile(string $routesFile): array
    {
        $routes = [];
        
        try {
            $content = file_get_contents($routesFile);
            
            // Simple regex to find route registrations
            // This is a basic implementation - could be enhanced
            preg_match_all('/(?:->(get|post|put|delete|patch))\([\'"]([^\'"]+)[\'"]/', $content, $matches, PREG_SET_ORDER);
            
            foreach ($matches as $match) {
                $routes[] = [
                    'method' => strtoupper($match[1]),
                    'path' => $match[2],
                    'source' => 'routes.php'
                ];
            }
        } catch (\Exception $e) {
            // Ignore errors in route discovery
        }
        
        return $routes;
    }
}

