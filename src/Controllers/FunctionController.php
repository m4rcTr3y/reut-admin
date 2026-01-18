<?php
declare(strict_types=1);

namespace Reut\Admin\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Reut\Admin\Models\FunctionModel;
use Reut\Admin\Services\FunctionService;
use Reut\Admin\Services\FunctionExecutor;
use Reut\Admin\Services\LogService;

/**
 * Function Controller
 * Admin API endpoints for function CRUD operations
 */
class FunctionController
{
    private array $config;
    private FunctionService $functionService;
    private LogService $logService;

    public function __construct()
    {
        // Get config from global
        global $config;
        $this->config = $config ?? [];
        $this->functionService = new FunctionService($this->config);
        $this->logService = new LogService($this->config);
    }

    /**
     * List all functions
     */
    public function listFunctions(Request $request, Response $response): Response
    {
        try {
            $functionModel = new FunctionModel($this->config);
            $functionModel->connect();

            // Use sqlQuery to get all functions (no pagination limit)
            $functions = $functionModel->sqlQuery("SELECT * FROM functions ORDER BY created_at DESC", []);
            
            $result = [];
            if ($functions && is_array($functions)) {
                foreach ($functions as $func) {
                    // Check if file exists
                    $func['file_exists'] = $this->functionService->functionFileExists($func['name']);
                    
                    // Parse params_schema if it's a string
                    if (isset($func['params_schema']) && is_string($func['params_schema'])) {
                        $func['params_schema'] = json_decode($func['params_schema'], true) ?? [];
                    }
                    
                    $result[] = $func;
                }
            }

            $response->getBody()->write(json_encode([
                'success' => true,
                'data' => $result
            ], JSON_UNESCAPED_SLASHES));

            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ], JSON_UNESCAPED_SLASHES));

            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(500);
        }
    }

    /**
     * Get a single function
     */
    public function getFunction(Request $request, Response $response, array $args): Response
    {
        try {
            $name = $args['name'] ?? null;
            if (!$name) {
                throw new \Exception('Function name is required');
            }

            $functionModel = new FunctionModel($this->config);
            $functionModel->connect();

            $function = $functionModel->findOne(['name' => $name]);
            
            if (!$function || !$function->results) {
                throw new \Exception("Function '{$name}' not found");
            }

            $func = $function->results;
            
            // Get function code
            $code = $this->functionService->readFunctionFile($name);
            $func['code'] = $code;
            
            // Parse params_schema
            if (isset($func['params_schema']) && is_string($func['params_schema'])) {
                $func['params_schema'] = json_decode($func['params_schema'], true) ?? [];
            }

            $response->getBody()->write(json_encode([
                'success' => true,
                'data' => $func
            ], JSON_UNESCAPED_SLASHES));

            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ], JSON_UNESCAPED_SLASHES));

            $statusCode = 404;
            if (strpos($e->getMessage(), 'required') !== false) {
                $statusCode = 400;
            }

            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus($statusCode);
        }
    }

    /**
     * Create a new function
     */
    public function createFunction(Request $request, Response $response): Response
    {
        try {
            $data = $request->getParsedBody();
            
            if (!isset($data['name']) || empty($data['name'])) {
                throw new \Exception('Function name is required');
            }

            if (!isset($data['code']) || empty($data['code'])) {
                throw new \Exception('Function code is required');
            }

            $name = $data['name'];
            
            // Validate function name
            if (!$this->functionService->validateFunctionName($name)) {
                throw new \Exception('Invalid function name. Only alphanumeric characters, underscores, and hyphens are allowed.');
            }

            // Check if function already exists (in database or as file)
            $functionModel = new FunctionModel($this->config);
            $functionModel->connect();

            $existing = $functionModel->findOne(['name' => $name]);
            if ($existing && $existing->results) {
                throw new \Exception("Function '{$name}' already exists. Please use the edit function to update it.");
            }

            // Also check if file exists (in case database record is missing but file exists)
            if ($this->functionService->functionFileExists($name)) {
                throw new \Exception("Function file '{$name}.php' already exists. Please delete it first or use a different name.");
            }

            // Validate PHP syntax
            $this->functionService->validatePhpSyntax($data['code']);

            // Create function file
            $this->functionService->createFunctionFile($name, $data['code']);

            // Create function record
            $functionData = [
                'name' => $name,
                'file_path' => $this->functionService->getFunctionFilePath($name),
                'description' => $data['description'] ?? null,
                'requires_auth' => isset($data['requires_auth']) ? (int)$data['requires_auth'] : 0,
                'params_schema' => isset($data['params_schema']) ? json_encode($data['params_schema']) : null,
                'http_methods' => $data['http_methods'] ?? 'GET,POST',
                'is_active' => isset($data['is_active']) ? (int)$data['is_active'] : 1,
            ];

            $functionModel->addOne($functionData);

            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => "Function '{$name}' created successfully",
                'data' => ['name' => $name]
            ], JSON_UNESCAPED_SLASHES));

            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ], JSON_UNESCAPED_SLASHES));

            $statusCode = 500;
            if (strpos($e->getMessage(), 'required') !== false || strpos($e->getMessage(), 'Invalid') !== false) {
                $statusCode = 400;
            } elseif (strpos($e->getMessage(), 'already exists') !== false) {
                $statusCode = 409;
            }

            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus($statusCode);
        }
    }

    /**
     * Update a function
     */
    public function updateFunction(Request $request, Response $response, array $args): Response
    {
        try {
            $name = $args['name'] ?? null;
            if (!$name) {
                throw new \Exception('Function name is required');
            }

            $data = $request->getParsedBody();

            $functionModel = new FunctionModel($this->config);
            $functionModel->connect();

            $function = $functionModel->findOne(['name' => $name]);
            if (!$function || !$function->results) {
                throw new \Exception("Function '{$name}' not found");
            }

            // Update code if provided
            if (isset($data['code'])) {
                // Validate PHP syntax
                $this->functionService->validatePhpSyntax($data['code']);
                
                // Update function file
                $this->functionService->updateFunctionFile($name, $data['code']);
            }

            // Update metadata
            $updateData = [];
            if (isset($data['description'])) {
                $updateData['description'] = $data['description'];
            }
            if (isset($data['requires_auth'])) {
                $updateData['requires_auth'] = (int)$data['requires_auth'];
            }
            if (isset($data['params_schema'])) {
                $updateData['params_schema'] = json_encode($data['params_schema']);
            }
            if (isset($data['http_methods'])) {
                $updateData['http_methods'] = $data['http_methods'];
            }
            if (isset($data['is_active'])) {
                $updateData['is_active'] = (int)$data['is_active'];
            }

            if (!empty($updateData)) {
                $functionModel->updateOne(['name' => $name], $updateData);
            }

            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => "Function '{$name}' updated successfully"
            ], JSON_UNESCAPED_SLASHES));

            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ], JSON_UNESCAPED_SLASHES));

            $statusCode = 500;
            if (strpos($e->getMessage(), 'not found') !== false) {
                $statusCode = 404;
            } elseif (strpos($e->getMessage(), 'required') !== false || strpos($e->getMessage(), 'Invalid') !== false) {
                $statusCode = 400;
            }

            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus($statusCode);
        }
    }

    /**
     * Delete a function
     */
    public function deleteFunction(Request $request, Response $response, array $args): Response
    {
        try {
            $name = $args['name'] ?? null;
            if (!$name) {
                throw new \Exception('Function name is required');
            }

            $functionModel = new FunctionModel($this->config);
            $functionModel->connect();

            $function = $functionModel->findOne(['name' => $name]);
            if (!$function || !$function->results) {
                throw new \Exception("Function '{$name}' not found");
            }

            // Delete function file
            $this->functionService->deleteFunctionFile($name);

            // Delete function record
            $functionModel->delete(['name' => $name]);

            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => "Function '{$name}' deleted successfully"
            ], JSON_UNESCAPED_SLASHES));

            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ], JSON_UNESCAPED_SLASHES));

            $statusCode = 500;
            if (strpos($e->getMessage(), 'not found') !== false) {
                $statusCode = 404;
            } elseif (strpos($e->getMessage(), 'required') !== false) {
                $statusCode = 400;
            }

            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus($statusCode);
        }
    }

    /**
     * Test/execute a function
     */
    public function testFunction(Request $request, Response $response, array $args): Response
    {
        try {
            $name = $args['name'] ?? null;
            if (!$name) {
                throw new \Exception('Function name is required');
            }

            $executor = new FunctionExecutor($this->config);
            $result = $executor->execute($name, $request);

            $response->getBody()->write(json_encode($result, JSON_UNESCAPED_SLASHES));
            return $response->withHeader('Content-Type', 'application/json');
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
    }

    /**
     * Get function logs/errors
     */
    public function getFunctionLogs(Request $request, Response $response, array $args): Response
    {
        try {
            $name = $args['name'] ?? null;
            if (!$name) {
                throw new \Exception('Function name is required');
            }

            $queryParams = $request->getQueryParams();
            $limit = (int)($queryParams['limit'] ?? 50);
            $offset = (int)($queryParams['offset'] ?? 0);

            $logs = $this->logService->getLogs(
                'function_error',
                'all',
                $limit,
                $offset,
                null,
                null,
                null
            );

            // Filter logs for this specific function
            $functionLogs = array_filter($logs['logs'] ?? [], function($log) use ($name) {
                $context = $log['context'] ?? [];
                return isset($context['function']) && $context['function'] === $name;
            });

            $response->getBody()->write(json_encode([
                'success' => true,
                'data' => array_values($functionLogs),
                'total' => count($functionLogs)
            ], JSON_UNESCAPED_SLASHES));

            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ], JSON_UNESCAPED_SLASHES));

            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(500);
        }
    }
}

