<?php
declare(strict_types=1);

namespace Reut\Admin\Routers;

use Slim\App;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Reut\Admin\Models\FunctionModel;
use Reut\Admin\Services\FunctionExecutor;
use Reut\Admin\AdminMiddleware;
use Reut\Middleware\JwtAuth;

/**
 * Function Router
 * Registers dynamic routes for all active functions
 */
class FunctionRouter
{
    private App $app;
    private array $config;
    private FunctionExecutor $executor;
    private JwtAuth $jwtAuth;

    public function __construct(App $app, array $config)
    {
        $this->app = $app;
        $this->config = $config;
        $this->executor = new FunctionExecutor($config);
        $this->jwtAuth = new JwtAuth($config);
    }

    /**
     * Register all active functions as routes
     */
    public function register(): void
    {
        // Check if functions feature is enabled
        $functionsEnabled = filter_var(
            $_ENV['FUNCTIONS_ENABLED'] ?? 'true',
            FILTER_VALIDATE_BOOLEAN
        );

        if (!$functionsEnabled) {
            return;
        }

        // Load all active functions
        $functionModel = new FunctionModel($this->config);
        $functionModel->connect();

        // Use sqlQuery to filter by is_active
        $functions = $functionModel->sqlQuery(
            "SELECT * FROM functions WHERE is_active = 1",
            []
        );

        if (!$functions || !is_array($functions) || empty($functions)) {
            return;
        }

        // Register each function as a route
        foreach ($functions as $function) {
            $this->registerFunctionRoute($function);
        }
    }

    /**
     * Register a single function as a route
     */
    private function registerFunctionRoute(array $function): void
    {
        $name = $function['name'];
        $requiresAuth = (bool)($function['requires_auth'] ?? false);
        $httpMethods = array_map('trim', explode(',', $function['http_methods'] ?? 'GET,POST'));

        // Create route handler
        $handler = function (Request $request, Response $response, array $args) use ($name) {
            try {
                $result = $this->executor->execute($name, $request);
                
                $response->getBody()->write(json_encode($result, JSON_UNESCAPED_SLASHES));
                return $response
                    ->withHeader('Content-Type', 'application/json')
                    ->withStatus(200);
            } catch (\Exception $e) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => $e->getMessage()
                ], JSON_UNESCAPED_SLASHES));
                
                $statusCode = 500;
                if (strpos($e->getMessage(), 'not found') !== false) {
                    $statusCode = 404;
                } elseif (strpos($e->getMessage(), 'not allowed') !== false) {
                    $statusCode = 405;
                }
                
                return $response
                    ->withHeader('Content-Type', 'application/json')
                    ->withStatus($statusCode);
            }
        };

        // Register route for each HTTP method
        foreach ($httpMethods as $method) {
            $method = strtoupper(trim($method));
            
            if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'])) {
                continue;
            }

            $route = $this->app->map([$method], "/functions/{$name}", $handler);

            // Add authentication middleware if required
            if ($requiresAuth) {
                // Use JWT auth middleware for function authentication
                $route->add($this->jwtAuth);
            }
        }
    }
}

