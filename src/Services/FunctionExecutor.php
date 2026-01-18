<?php
declare(strict_types=1);

namespace Reut\Admin\Services;

use Psr\Http\Message\ServerRequestInterface as Request;
use Reut\Admin\Models\FunctionModel;
use Reut\Admin\Services\LogService;

/**
 * Function Executor
 * Loads and executes function files
 */
class FunctionExecutor
{
    private array $config;
    private FunctionService $functionService;
    private LogService $logService;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->functionService = new FunctionService($config);
        $this->logService = new LogService($config);
    }

    /**
     * Execute a function
     */
    public function execute(string $name, Request $request): array
    {
        // Load function metadata
        $functionModel = new FunctionModel($this->config);
        $functionModel->connect();
        
        $functionData = $functionModel->findOne(['name' => $name, 'is_active' => 1]);
        
        if (!$functionData || !$functionData->results) {
            throw new \Exception("Function '{$name}' not found or inactive");
        }
        
        $function = $functionData->results;
        
        // Check HTTP method
        $allowedMethods = array_map('trim', explode(',', $function['http_methods'] ?? 'GET,POST'));
        $requestMethod = $request->getMethod();
        
        if (!in_array($requestMethod, $allowedMethods)) {
            throw new \Exception("Method {$requestMethod} not allowed. Allowed methods: " . implode(', ', $allowedMethods));
        }
        
        // Load and execute function file
        $filePath = $this->functionService->getFunctionFilePath($name);
        
        if (!file_exists($filePath)) {
            throw new \Exception("Function file not found: {$filePath}");
        }
        
        // Parse parameters
        $params = $this->parseParameters($request, $function['params_schema'] ?? null);
        
        // Execute function in isolated scope
        try {
            $functionCode = file_get_contents($filePath);
            
            // Create isolated execution environment
            $result = $this->executeInIsolation($functionCode, $request, $params);
            
            return [
                'success' => true,
                'data' => $result
            ];
        } catch (\Throwable $e) {
            // Log error
            $this->logService->log(
                'function_error',
                'error',
                "Function '{$name}' execution error: " . $e->getMessage(),
                [
                    'function' => $name,
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $this->sanitizeTrace($e->getTraceAsString())
                ],
                null,
                $this->getClientIp($request),
                $request->getHeaderLine('User-Agent')
            );
            
            throw $e;
        }
    }

    /**
     * Execute function code in isolation
     */
    private function executeInIsolation(string $code, Request $request, array $params)
    {
        // Extract the function from the code
        // The code should be: <?php return function($request, $params) { ... };
        
        // Create a temporary file and include it
        $tempFile = tempnam(sys_get_temp_dir(), 'reut_function_exec_');
        file_put_contents($tempFile, $code);
        
        try {
            // Include the file to get the function
            $function = include $tempFile;
            
            if (!is_callable($function)) {
                throw new \Exception("Function file must return a callable");
            }
            
            // Execute the function
            return $function($request, $params);
        } finally {
            // Clean up temp file
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    /**
     * Parse parameters from request
     */
    private function parseParameters(Request $request, ?string $paramsSchemaJson): array
    {
        $params = [];
        
        // Get query parameters
        $queryParams = $request->getQueryParams();
        $params = array_merge($params, $queryParams);
        
        // Get body parameters
        $bodyParams = $request->getParsedBody();
        if (is_array($bodyParams)) {
            $params = array_merge($params, $bodyParams);
        }
        
        // Validate against schema if provided
        if ($paramsSchemaJson) {
            $schema = json_decode($paramsSchemaJson, true);
            if ($schema && is_array($schema)) {
                $params = $this->validateParameters($params, $schema);
            }
        }
        
        return $params;
    }

    /**
     * Validate parameters against schema
     */
    private function validateParameters(array $params, array $schema): array
    {
        $validated = [];
        
        foreach ($schema as $key => $rule) {
            $value = $params[$key] ?? null;
            
            // Check required
            if (isset($rule['required']) && $rule['required'] && $value === null) {
                throw new \Exception("Required parameter '{$key}' is missing");
            }
            
            // Skip if not required and not provided
            if ($value === null) {
                continue;
            }
            
            // Type validation
            if (isset($rule['type'])) {
                $type = $rule['type'];
                switch ($type) {
                    case 'string':
                        if (!is_string($value)) {
                            throw new \Exception("Parameter '{$key}' must be a string");
                        }
                        break;
                    case 'integer':
                    case 'int':
                        if (!is_numeric($value)) {
                            throw new \Exception("Parameter '{$key}' must be an integer");
                        }
                        $value = (int)$value;
                        break;
                    case 'number':
                    case 'float':
                        if (!is_numeric($value)) {
                            throw new \Exception("Parameter '{$key}' must be a number");
                        }
                        $value = (float)$value;
                        break;
                    case 'boolean':
                    case 'bool':
                        if (!is_bool($value) && !in_array(strtolower($value), ['true', 'false', '1', '0', 'yes', 'no'])) {
                            throw new \Exception("Parameter '{$key}' must be a boolean");
                        }
                        $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                        break;
                    case 'array':
                        if (!is_array($value)) {
                            throw new \Exception("Parameter '{$key}' must be an array");
                        }
                        break;
                }
            }
            
            $validated[$key] = $value;
        }
        
        return $validated;
    }

    /**
     * Get client IP address
     */
    private function getClientIp(Request $request): string
    {
        $headers = [
            'X-Forwarded-For',
            'X-Real-IP',
            'CF-Connecting-IP', // Cloudflare
        ];
        
        foreach ($headers as $header) {
            $ip = $request->getHeaderLine($header);
            if (!empty($ip)) {
                // X-Forwarded-For can contain multiple IPs
                $ips = explode(',', $ip);
                return trim($ips[0]);
            }
        }
        
        $serverParams = $request->getServerParams();
        return $serverParams['REMOTE_ADDR'] ?? 'unknown';
    }

    /**
     * Sanitize trace for logging
     */
    private function sanitizeTrace(string $trace): string
    {
        // Remove sensitive paths
        $projectRoot = \Reut\Support\ProjectPath::root();
        $trace = str_replace($projectRoot, '[PROJECT_ROOT]', $trace);
        
        // Limit trace length
        if (strlen($trace) > 2000) {
            $trace = substr($trace, 0, 2000) . '... (truncated)';
        }
        
        return $trace;
    }
}

