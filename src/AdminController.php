<?php
declare(strict_types=1);

namespace Reut\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Reut\Admin\Controllers\SchemaController as AdminSchemaController;
use Reut\Admin\Controllers\ModelController;
use Reut\Admin\Controllers\MigrationController;
use Reut\Admin\Controllers\DataController;
use Reut\Admin\Controllers\QueryController;
use Reut\Admin\Controllers\LogsController;
use Reut\Admin\Controllers\AnalyticsController;
use Reut\Admin\Controllers\UserController;
use Reut\Admin\Controllers\AuthController;
use Reut\Admin\Controllers\FunctionController;
use Reut\Admin\Middleware\SecurityHeadersMiddleware;
use Reut\Admin\Middleware\AdminRateLimitMiddleware;
use Reut\Admin\Middleware\AdminCsrfMiddleware;
use Reut\Admin\Middleware\RoleMiddleware;
use Reut\Admin\Middleware\RequestSizeLimitMiddleware;
use Reut\Admin\Middleware\ApiKeyMiddleware;
use Reut\Admin\Services\PermissionService;

/**
 * Main Admin Controller
 * Registers all admin routes and serves the React admin UI
 */
class AdminController
{
    private $app;
    private $config;
    private $adminPath;

    public function __construct(App $app, array $config, string $adminPath = '/admin')
    {
        $this->app = $app;
        $this->config = $config;
        $this->adminPath = rtrim($adminPath, '/');
    }

    /**
     * Register all admin routes
     */
    public function register(): void
    {
        $adminPath = $this->adminPath;
        $adminMiddleware = new AdminMiddleware($this->config);
        $securityHeadersMiddleware = new SecurityHeadersMiddleware($this->config);
        $rateLimitMiddleware = new AdminRateLimitMiddleware($this->config);
        $csrfMiddleware = new AdminCsrfMiddleware($this->config);
        $requestSizeLimitMiddleware = new RequestSizeLimitMiddleware($this->config);

        // Auth routes group with security headers and rate limiting
        // Using v1 for API versioning
        $authGroup = $this->app->group($adminPath . '/api/v1/auth', function ($group) {
            $group->post('/login', function (Request $request, Response $response) {
                $controller = new AuthController();
                return $controller->login($request, $response);
            });
            $group->post('/register', function (Request $request, Response $response) {
                $controller = new AuthController();
                return $controller->register($request, $response);
            });
            $group->post('/refresh', function (Request $request, Response $response) {
                $controller = new AuthController();
                return $controller->refresh($request, $response);
            });
        });
        $authGroup->add($requestSizeLimitMiddleware);
        $authGroup->add($rateLimitMiddleware);
        $authGroup->add($securityHeadersMiddleware);

        // Protected API routes with versioning (v1)
        $protectedGroup = $this->app->group($adminPath . '/api/v1', function ($group) use ($adminPath) {
            
            // Schema route (for ModelEditor schema view)
            $group->get('/schema', function (Request $request, Response $response) {
                $controller = new AdminSchemaController();
                return $controller->getSchema($request, $response);
            });
            
            // Model routes
            $group->get('/models', function (Request $request, Response $response) {
                $controller = new ModelController();
                return $controller->listModels($request, $response);
            });
            $group->get('/models/{name}', function (Request $request, Response $response, array $args) {
                $controller = new ModelController();
                return $controller->getModel($request, $response, $args);
            });
            $group->post('/models', function (Request $request, Response $response) {
                $controller = new ModelController();
                return $controller->createModel($request, $response);
            })->add(new RoleMiddleware(PermissionService::PERMISSION_CREATE_MODEL));
            $group->put('/models/{name}', function (Request $request, Response $response, array $args) {
                $controller = new ModelController();
                return $controller->updateModel($request, $response, $args);
            })->add(new RoleMiddleware(PermissionService::PERMISSION_EDIT_MODEL));
            $group->delete('/models/{name}', function (Request $request, Response $response, array $args) {
                $controller = new ModelController();
                return $controller->deleteModel($request, $response, $args);
            })->add(new RoleMiddleware(PermissionService::PERMISSION_DELETE_MODEL));
            $group->post('/models/{name}/migrate', function (Request $request, Response $response, array $args) {
                $controller = new ModelController();
                return $controller->generateMigration($request, $response, $args);
            });
            $group->post('/models/{name}/generate-router', function (Request $request, Response $response, array $args) {
                $controller = new ModelController();
                return $controller->generateRouter($request, $response, $args);
            });
            
            // Migration routes
            $group->get('/migrations/status', function (Request $request, Response $response) {
                $controller = new MigrationController();
                return $controller->getStatus($request, $response);
            });
            $group->post('/migrations/apply', function (Request $request, Response $response) {
                $controller = new MigrationController();
                return $controller->apply($request, $response);
            })->add(new RoleMiddleware(PermissionService::PERMISSION_RUN_MIGRATIONS));
            $group->post('/migrations/rollback', function (Request $request, Response $response) {
                $controller = new MigrationController();
                return $controller->rollback($request, $response);
            })->add(new RoleMiddleware(PermissionService::PERMISSION_ROLLBACK_MIGRATIONS));
            $group->get('/migrations/history', function (Request $request, Response $response) {
                $controller = new MigrationController();
                return $controller->getHistory($request, $response);
            });
            $group->post('/migrations/export', function (Request $request, Response $response) {
                $controller = new MigrationController();
                return $controller->export($request, $response);
            });
            $group->post('/migrations/import', function (Request $request, Response $response) {
                $controller = new MigrationController();
                return $controller->import($request, $response);
            });
            $group->delete('/migrations/{id}', function (Request $request, Response $response, array $args) {
                $controller = new MigrationController();
                return $controller->deleteMigration($request, $response, $args);
            });
            
            // Data routes
            $group->get('/data/{table}', function (Request $request, Response $response, array $args) {
                $controller = new DataController();
                return $controller->getData($request, $response, $args);
            });
            $group->get('/data/{table}/{id}', function (Request $request, Response $response, array $args) {
                $controller = new DataController();
                return $controller->getRecord($request, $response, $args);
            });
            $group->post('/data/{table}', function (Request $request, Response $response, array $args) {
                $controller = new DataController();
                return $controller->createRecord($request, $response, $args);
            })->add(new RoleMiddleware(PermissionService::PERMISSION_CREATE_DATA));
            $group->put('/data/{table}/{id}', function (Request $request, Response $response, array $args) {
                $controller = new DataController();
                return $controller->updateRecord($request, $response, $args);
            })->add(new RoleMiddleware(PermissionService::PERMISSION_EDIT_DATA));
            $group->delete('/data/{table}/{id}', function (Request $request, Response $response, array $args) {
                $controller = new DataController();
                return $controller->deleteRecord($request, $response, $args);
            })->add(new RoleMiddleware(PermissionService::PERMISSION_DELETE_DATA));
            
            // Query routes
            $group->post('/query', function (Request $request, Response $response) {
                $controller = new QueryController();
                return $controller->executeQuery($request, $response);
            })->add(new RoleMiddleware(PermissionService::PERMISSION_EXECUTE_QUERY));
            $group->get('/query/history', function (Request $request, Response $response) {
                $controller = new QueryController();
                return $controller->getHistory($request, $response);
            });
            
            // Logs routes
            $group->get('/logs', function (Request $request, Response $response) {
                $controller = new LogsController();
                return $controller->getLogs($request, $response);
            });
            $group->get('/logs/stats', function (Request $request, Response $response) {
                $controller = new LogsController();
                return $controller->getStats($request, $response);
            });
            $group->post('/logs/clear', function (Request $request, Response $response) {
                $controller = new LogsController();
                return $controller->clearLogs($request, $response);
            });
            $group->get('/logs/export', function (Request $request, Response $response) {
                $controller = new LogsController();
                return $controller->exportLogs($request, $response);
            });
            
            // Analytics routes
            $group->get('/analytics', function (Request $request, Response $response) {
                $controller = new AnalyticsController();
                return $controller->getAnalytics($request, $response);
            });
            
            // User routes (if auth enabled)
            $group->get('/users', function (Request $request, Response $response) {
                $controller = new UserController();
                return $controller->getUsers($request, $response);
            });
            $group->post('/users', function (Request $request, Response $response) {
                $controller = new UserController();
                return $controller->createUser($request, $response);
            })->add(new RoleMiddleware(PermissionService::PERMISSION_MANAGE_USERS));
            
            // Documentation route (used by API Management for OpenAPI docs)
            $group->get('/docs', function (Request $request, Response $response) {
                $controller = new \Reut\Admin\Controllers\DocsController();
                return $controller->getDocs($request, $response);
            });
            
            // Environment variables routes
            $group->get('/env', function (Request $request, Response $response) {
                $controller = new \Reut\Admin\Controllers\EnvController();
                return $controller->getEnv($request, $response);
            });
            $group->get('/env/{key}', function (Request $request, Response $response, array $args) {
                $controller = new \Reut\Admin\Controllers\EnvController();
                return $controller->getEnvVariable($request, $response, $args);
            });
            $group->post('/env', function (Request $request, Response $response) {
                $controller = new \Reut\Admin\Controllers\EnvController();
                return $controller->updateEnvVariable($request, $response);
            })->add(new RoleMiddleware(PermissionService::PERMISSION_MANAGE_ENV));
            $group->put('/env', function (Request $request, Response $response) {
                $controller = new \Reut\Admin\Controllers\EnvController();
                return $controller->updateEnvVariable($request, $response);
            })->add(new RoleMiddleware(PermissionService::PERMISSION_MANAGE_ENV));
            $group->delete('/env/{key}', function (Request $request, Response $response, array $args) {
                $controller = new \Reut\Admin\Controllers\EnvController();
                return $controller->deleteEnvVariable($request, $response, $args);
            })->add(new RoleMiddleware(PermissionService::PERMISSION_MANAGE_ENV));
            
            // Session management routes
            $group->get('/sessions', function (Request $request, Response $response) {
                $controller = new \Reut\Admin\Controllers\SessionController();
                return $controller->getSessions($request, $response);
            });
            $group->delete('/sessions/{sessionId}', function (Request $request, Response $response, array $args) {
                $controller = new \Reut\Admin\Controllers\SessionController();
                return $controller->revokeSession($request, $response, $args);
            });
            $group->post('/sessions/revoke-all', function (Request $request, Response $response) {
                $controller = new \Reut\Admin\Controllers\SessionController();
                return $controller->revokeAllSessions($request, $response);
            });
            
            // Admin user management routes
            $group->get('/admin-users', function (Request $request, Response $response) {
                $controller = new \Reut\Admin\Controllers\AdminUserController();
                return $controller->getAdminUsers($request, $response);
            });
            $group->get('/admin-users/{id}', function (Request $request, Response $response, array $args) {
                $controller = new \Reut\Admin\Controllers\AdminUserController();
                return $controller->getAdminUser($request, $response, $args);
            });
            $group->post('/admin-users', function (Request $request, Response $response) {
                $controller = new \Reut\Admin\Controllers\AdminUserController();
                return $controller->createAdminUser($request, $response);
            })->add(new RoleMiddleware(PermissionService::PERMISSION_MANAGE_USERS));
            $group->put('/admin-users/{id}', function (Request $request, Response $response, array $args) {
                $controller = new \Reut\Admin\Controllers\AdminUserController();
                return $controller->updateAdminUser($request, $response, $args);
            })->add(new RoleMiddleware(PermissionService::PERMISSION_MANAGE_USERS));
            $group->delete('/admin-users/{id}', function (Request $request, Response $response, array $args) {
                $controller = new \Reut\Admin\Controllers\AdminUserController();
                return $controller->deleteAdminUser($request, $response, $args);
            })->add(new RoleMiddleware(PermissionService::PERMISSION_MANAGE_USERS));
            $group->get('/roles', function (Request $request, Response $response) {
                $controller = new \Reut\Admin\Controllers\AdminUserController();
                return $controller->getRoles($request, $response);
            });
            
            // API management routes
            $group->get('/api/routes', function (Request $request, Response $response) {
                $controller = new \Reut\Admin\Controllers\ApiController();
                return $controller->getRoutes($request, $response);
            });
            $group->get('/api/routes/{method}/{path}', function (Request $request, Response $response, array $args) {
                $controller = new \Reut\Admin\Controllers\ApiController();
                return $controller->getRouteDetails($request, $response, $args);
            });
            $group->get('/api/docs', function (Request $request, Response $response) {
                $controller = new \Reut\Admin\Controllers\ApiController();
                return $controller->generateApiDocs($request, $response);
            });
            
            // API key management routes
            $group->get('/api-keys', function (Request $request, Response $response) {
                $controller = new \Reut\Admin\Controllers\ApiKeyController();
                return $controller->getApiKeys($request, $response);
            });
            $group->get('/api-keys/{id}', function (Request $request, Response $response, array $args) {
                $controller = new \Reut\Admin\Controllers\ApiKeyController();
                return $controller->getApiKey($request, $response, $args);
            });
            $group->post('/api-keys', function (Request $request, Response $response) {
                $controller = new \Reut\Admin\Controllers\ApiKeyController();
                return $controller->createApiKey($request, $response);
            })->add(new RoleMiddleware(PermissionService::PERMISSION_MANAGE_ENV));
            $group->put('/api-keys/{id}', function (Request $request, Response $response, array $args) {
                $controller = new \Reut\Admin\Controllers\ApiKeyController();
                return $controller->updateApiKey($request, $response, $args);
            })->add(new RoleMiddleware(PermissionService::PERMISSION_MANAGE_ENV));
            $group->delete('/api-keys/{id}', function (Request $request, Response $response, array $args) {
                $controller = new \Reut\Admin\Controllers\ApiKeyController();
                return $controller->deleteApiKey($request, $response, $args);
            })->add(new RoleMiddleware(PermissionService::PERMISSION_MANAGE_ENV));
            $group->post('/api-keys/{id}/regenerate-secret', function (Request $request, Response $response, array $args) {
                $controller = new \Reut\Admin\Controllers\ApiKeyController();
                return $controller->regenerateSecret($request, $response, $args);
            })->add(new RoleMiddleware(PermissionService::PERMISSION_MANAGE_ENV));
            
            // Model relationship and index management routes
            $group->get('/models/{name}/relationships', function (Request $request, Response $response, array $args) {
                $controller = new ModelController();
                return $controller->getRelationships($request, $response, $args);
            });
            $group->post('/models/{name}/relationships', function (Request $request, Response $response, array $args) {
                $controller = new ModelController();
                return $controller->addRelationship($request, $response, $args);
            })->add(new RoleMiddleware(PermissionService::PERMISSION_EDIT_MODEL));
            $group->get('/models/{name}/indexes', function (Request $request, Response $response, array $args) {
                $controller = new ModelController();
                return $controller->getIndexes($request, $response, $args);
            });
            $group->post('/models/{name}/indexes', function (Request $request, Response $response, array $args) {
                $controller = new ModelController();
                return $controller->createIndex($request, $response, $args);
            })->add(new RoleMiddleware(PermissionService::PERMISSION_EDIT_MODEL));
            $group->delete('/models/{name}/indexes/{index_name}', function (Request $request, Response $response, array $args) {
                $controller = new ModelController();
                return $controller->deleteIndex($request, $response, $args);
            })->add(new RoleMiddleware(PermissionService::PERMISSION_EDIT_MODEL));
            
            // Column management routes
            $group->get('/models/{name}/columns', function (Request $request, Response $response, array $args) {
                $controller = new ModelController();
                return $controller->getColumns($request, $response, $args);
            });
            $group->post('/models/{name}/columns', function (Request $request, Response $response, array $args) {
                $controller = new ModelController();
                return $controller->addColumn($request, $response, $args);
            })->add(new RoleMiddleware(PermissionService::PERMISSION_EDIT_MODEL));
            $group->put('/models/{name}/columns/{columnName}', function (Request $request, Response $response, array $args) {
                $controller = new ModelController();
                return $controller->updateColumn($request, $response, $args);
            })->add(new RoleMiddleware(PermissionService::PERMISSION_EDIT_MODEL));
            $group->delete('/models/{name}/columns/{columnName}', function (Request $request, Response $response, array $args) {
                $controller = new ModelController();
                return $controller->deleteColumn($request, $response, $args);
            })->add(new RoleMiddleware(PermissionService::PERMISSION_EDIT_MODEL));
            
            // Function management routes
            $group->get('/functions', function (Request $request, Response $response) {
                $controller = new FunctionController();
                return $controller->listFunctions($request, $response);
            });
            $group->get('/functions/{name}', function (Request $request, Response $response, array $args) {
                $controller = new FunctionController();
                return $controller->getFunction($request, $response, $args);
            });
            $group->post('/functions', function (Request $request, Response $response) {
                $controller = new FunctionController();
                return $controller->createFunction($request, $response);
            })->add(new RoleMiddleware(PermissionService::PERMISSION_CREATE_MODEL));
            $group->put('/functions/{name}', function (Request $request, Response $response, array $args) {
                $controller = new FunctionController();
                return $controller->updateFunction($request, $response, $args);
            })->add(new RoleMiddleware(PermissionService::PERMISSION_EDIT_MODEL));
            $group->delete('/functions/{name}', function (Request $request, Response $response, array $args) {
                $controller = new FunctionController();
                return $controller->deleteFunction($request, $response, $args);
            })->add(new RoleMiddleware(PermissionService::PERMISSION_DELETE_MODEL));
            $group->post('/functions/{name}/test', function (Request $request, Response $response, array $args) {
                $controller = new FunctionController();
                return $controller->testFunction($request, $response, $args);
            });
            $group->get('/functions/{name}/logs', function (Request $request, Response $response, array $args) {
                $controller = new FunctionController();
                return $controller->getFunctionLogs($request, $response, $args);
            });
        });

        // Apply middleware to protected routes
        $protectedGroup->add($requestSizeLimitMiddleware);
        $protectedGroup->add($rateLimitMiddleware);
        $protectedGroup->add($securityHeadersMiddleware);
        $protectedGroup->add($adminMiddleware);
        $protectedGroup->add($csrfMiddleware); // CSRF after auth so we have user info
        
        // Add logging middleware after auth (so we have user info)
        $logMiddleware = new \Reut\Admin\Middleware\AdminLogMiddleware($this->config);
        $protectedGroup->add($logMiddleware);

        // Register API Key middleware globally (for project API routes, not admin routes)
        // This allows API keys to be used for authenticating project API endpoints
        // The middleware is smart enough to skip admin routes automatically
        $apiKeyMiddleware = new ApiKeyMiddleware($this->config);
        $this->app->add($apiKeyMiddleware);

        // Serve static assets first (before catch-all) with security headers
        // Register assets route BEFORE UI catch-all to ensure it matches first
        // Use a more specific route pattern to ensure it matches before the catch-all
        $this->app->get($adminPath . '/assets/{path:.*}', [$this, 'serveAsset'])
            ->add($securityHeadersMiddleware);
        
        // Serve React app (catch-all for admin UI) with security headers
        // Note: This should be registered LAST so API routes take precedence
        // Only match GET requests that are NOT API routes
        $uiGroup = $this->app->group($adminPath, function ($group) {
            $group->get('[/{path:.*}]', [$this, 'serveAdminUI']);
        });
        $uiGroup->add($securityHeadersMiddleware);
    }

    /**
     * Serve static assets
     */
    public function serveAsset(Request $request, Response $response, array $args): Response
    {
        // Extract path from route arguments
        $path = $args['path'] ?? '';
        
        // Sanitize path to prevent directory traversal
        $path = ltrim($path, '/');
        $path = str_replace('..', '', $path);
        
        // Remove any leading slashes and normalize
        $path = trim($path, '/');
        
        if (empty($path)) {
            $response->getBody()->write('Asset path is required');
            return $response->withStatus(404)->withHeader('Content-Type', 'text/plain');
        }
        
        $assetsDir = $this->findAssetsDirectory();
        
        if (!$assetsDir) {
            $response->getBody()->write('Assets directory not found');
            return $response->withStatus(404)->withHeader('Content-Type', 'text/plain');
        }
        
        // Try assets subdirectory first (Vite build structure: assets/assets/file.js)
        $filePath = $assetsDir . '/assets/' . $path;
        if (!file_exists($filePath) || !is_file($filePath)) {
            // Try root assets directory
            $filePath = $assetsDir . '/' . $path;
        }
        
        // Security check: ensure file is within assets directory
        $realFilePath = realpath($filePath);
        $realAssetsDir = realpath($assetsDir);
        if (!$realFilePath || !$realAssetsDir || strpos($realFilePath, $realAssetsDir) !== 0) {
            $response->getBody()->write('Asset not found: ' . htmlspecialchars($path));
            return $response->withStatus(404)->withHeader('Content-Type', 'text/plain');
        }
        
        if (file_exists($filePath) && is_file($filePath)) {
            $mimeType = $this->getMimeType($filePath);
            $content = file_get_contents($filePath);
            
            // Add cache headers for static assets
            $response->getBody()->write($content);
            return $response
                ->withHeader('Content-Type', $mimeType)
                ->withHeader('Cache-Control', 'public, max-age=31536000')
                ->withHeader('Content-Length', (string)strlen($content));
        }
        
        $response->getBody()->write('Asset not found: ' . htmlspecialchars($path));
        return $response->withStatus(404)->withHeader('Content-Type', 'text/plain');
    }
    
    private function findAssetsDirectory(): ?string
    {
        // Use reflection to get the actual file location (follows symlinks)
        $reflection = new \ReflectionClass($this);
        $filePath = $reflection->getFileName();
        $realFilePath = realpath($filePath);
        
        // Get package directory from AdminController.php location
        // AdminController.php is in src/, so go up 2 levels to get package root
        $packageDir = dirname(dirname($realFilePath));
        $assetsDir = $packageDir . '/assets';
        
        // Use realpath to follow symlinks
        $realAssetsDir = realpath($assetsDir);
        if ($realAssetsDir && is_dir($realAssetsDir) && file_exists($realAssetsDir . '/index.html')) {
            return $realAssetsDir;
        }
        
        // Fallback: try relative to current file
        $adminPackageDir = __DIR__ . '/../../';
        $assetsDir = $adminPackageDir . 'assets';
        $realAssetsDir = realpath($assetsDir);
        if ($realAssetsDir && is_dir($realAssetsDir) && file_exists($realAssetsDir . '/index.html')) {
            return $realAssetsDir;
        }
        
        return null;
    }

    /**
     * Serve the React admin UI
     * This is a catch-all route that serves index.html for all non-API, non-asset routes
     * This allows React Router to handle client-side routing
     */
    public function serveAdminUI(Request $request, Response $response): Response
    {
        // Only serve UI for GET requests - reject POST/PUT/DELETE etc
        if ($request->getMethod() !== 'GET') {
            $response->getBody()->write(json_encode([
                'error' => 'Method not allowed',
                'message' => 'This endpoint only accepts GET requests. API endpoints are at /admin/api/v1/'
            ], JSON_UNESCAPED_SLASHES));
            return $response->withStatus(405)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }
        
        // This catch-all route should only be reached for non-API, non-asset routes
        // Since API and asset routes are registered before this, if we reach here,
        // it's safe to assume this is a React Router route and serve index.html
        $assetsDir = $this->findAssetsDirectory();
        
        if (!$assetsDir) {
            // Fallback message
            $response->getBody()->write('
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Reut Admin - Setup Required</title>
                    <style>
                        body { font-family: system-ui; padding: 2rem; text-align: center; }
                        .message { max-width: 600px; margin: 0 auto; padding: 2rem; background: #f8fafc; border-radius: 12px; }
                    </style>
                </head>
                <body>
                    <div class="message">
                        <h1>Reut Admin Dashboard</h1>
                        <p>Admin UI assets not found. Please build the React admin UI.</p>
                        <p>Run: <code>cd vendor/m4rc/reut-admin/admin-ui && npm install && npm run build</code></p>
                    </div>
                </body>
                </html>
            ');
            return $response->withHeader('Content-Type', 'text/html');
        }
        
        // Get the path from route arguments
        $route = $request->getAttribute('route');
        $path = $route ? ($route->getArgument('path') ?? '') : '';
        
        // If requesting a specific file that exists, serve it (for direct file access)
        // But skip this for React Router routes - let them all serve index.html
        // Only serve actual files if they exist and are not HTML routes
        if ($path && $path !== 'index.html' && $path !== '' && !empty(pathinfo($path, PATHINFO_EXTENSION))) {
            // Try assets subdirectory first (Vite build structure)
            $filePath = $assetsDir . '/assets/' . $path;
            if (!file_exists($filePath) || !is_file($filePath)) {
                // Try root assets directory
                $filePath = $assetsDir . '/' . $path;
            }
            
            // Only serve if it's a real file (not a route)
            if (file_exists($filePath) && is_file($filePath)) {
                $mimeType = $this->getMimeType($filePath);
                $content = file_get_contents($filePath);
                $response->getBody()->write($content);
                return $response->withHeader('Content-Type', $mimeType);
            }
        }
        
        // Serve index.html for all routes (React Router will handle routing)
        // This is the key: always serve index.html for any route that doesn't match API or assets
        $indexPath = $assetsDir . '/index.html';
        if (file_exists($indexPath)) {
            $html = file_get_contents($indexPath);
            $response->getBody()->write($html);
            return $response
                ->withStatus(200)
                ->withHeader('Content-Type', 'text/html; charset=utf-8')
                ->withHeader('Content-Length', (string)strlen($html));
        }
        
        // Fallback if assets don't exist
        $response->getBody()->write('
            <!DOCTYPE html>
            <html>
            <head>
                <title>Reut Admin - Setup Required</title>
                <style>
                    body { font-family: system-ui; padding: 2rem; text-align: center; }
                    .message { max-width: 600px; margin: 0 auto; padding: 2rem; background: #f8fafc; border-radius: 12px; }
                </style>
            </head>
            <body>
                <div class="message">
                    <h1>Reut Admin Dashboard</h1>
                    <p>Admin UI assets not found. Please build the React admin UI and copy the build to the assets directory.</p>
                    <p>Run: <code>cd admin-ui && npm run build && cp -r dist/* ../assets/</code></p>
                </div>
            </body>
            </html>
        ');
        return $response->withHeader('Content-Type', 'text/html');
    }

    private function getMimeType(string $filePath): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mimeTypes = [
            'js' => 'application/javascript',
            'mjs' => 'application/javascript',
            'css' => 'text/css',
            'json' => 'application/json',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'eot' => 'application/vnd.ms-fontobject',
            'html' => 'text/html',
        ];
        return $mimeTypes[$extension] ?? 'application/octet-stream';
    }
}

