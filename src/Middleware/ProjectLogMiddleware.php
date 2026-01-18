<?php
declare(strict_types=1);

namespace Reut\Admin\Middleware;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Http\Message\ResponseInterface as Response;
use Reut\Admin\Services\LogService;

/**
 * Project Log Middleware
 * Logs all API requests to the project (not just admin routes)
 */
class ProjectLogMiddleware
{
    private LogService $logService;
    private array $config;
    private bool $enabled;
    private array $allowedMethods;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->logService = new LogService($config);
        
        // Check if project logging is enabled
        $this->enabled = filter_var(
            $_ENV['PROJECT_LOGGING_ENABLED'] ?? 'true',
            FILTER_VALIDATE_BOOLEAN
        );
        
        // Get allowed methods to log
        $methodsConfig = $_ENV['PROJECT_LOGGING_METHODS'] ?? 'GET,POST,PUT,DELETE,PATCH';
        $this->allowedMethods = array_map('trim', explode(',', $methodsConfig));
    }

    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        // Skip if logging is disabled
        if (!$this->enabled) {
            return $handler->handle($request);
        }

        $startTime = microtime(true);
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();
        
        // Skip admin routes (already logged by AdminLogMiddleware)
        if (strpos($path, '/admin') === 0) {
            return $handler->handle($request);
        }
        
        // Skip function routes (they log their own errors)
        if (strpos($path, '/functions/') === 0) {
            return $handler->handle($request);
        }

        // Check if method should be logged
        if (!in_array($method, $this->allowedMethods)) {
            return $handler->handle($request);
        }

        $ipAddress = $this->getClientIp($request);
        $userAgent = $request->getHeaderLine('User-Agent');
        
        // Get user ID from JWT if available
        $userId = null;
        $authHeader = $request->getHeader('Authorization');
        if ($authHeader && !empty($authHeader[0])) {
            $token = str_replace('Bearer ', '', $authHeader[0]);
            try {
                $jwtAuth = new \Reut\Middleware\JwtAuth($this->config);
                $decoded = $jwtAuth->validateToken($token);
                if ($decoded && isset($decoded->sub)) {
                    $userId = $decoded->sub;
                }
            } catch (\Exception $e) {
                // Ignore auth errors - just log without user ID
            }
        }

        // Skip logging for certain paths
        if ($this->shouldSkipLogging($path)) {
            return $handler->handle($request);
        }

        // Log request
        $requestBody = $request->getBody()->getContents();
        $request->getBody()->rewind(); // Reset stream for handler
        
        $requestData = [];
        if (!empty($requestBody)) {
            $parsed = json_decode($requestBody, true);
            if ($parsed !== null) {
                $requestData = $parsed;
            }
        }

        $context = [
            'method' => $method,
            'path' => $path,
            'query' => $request->getQueryParams(),
            'request' => $this->sanitizeRequestData($requestData),
        ];

        $logLevel = 'info';

        try {
            // Handle request
            $response = $handler->handle($request);
            
            // Calculate execution time
            $executionTime = microtime(true) - $startTime;
            
            // Get response status
            $statusCode = $response->getStatusCode();
            
            // Update context with response info
            $context['status'] = $statusCode;
            $context['executionTime'] = round($executionTime * 1000, 2); // milliseconds
            
            // Log error level for 4xx and 5xx responses
            if ($statusCode >= 400) {
                $logLevel = $statusCode >= 500 ? 'error' : 'warning';
            }

            // Log the request/response
            $this->logService->log(
                'project_api',
                $logLevel,
                "{$method} {$path} - {$statusCode}",
                $context,
                $userId,
                $ipAddress,
                $userAgent
            );

            return $response;
        } catch (\Throwable $e) {
            // Log exception
            $executionTime = microtime(true) - $startTime;
            $context['error'] = $e->getMessage();
            $context['executionTime'] = round($executionTime * 1000, 2);
            $context['trace'] = $this->sanitizeTrace($e->getTraceAsString());

            $this->logService->log(
                'project_api',
                'error',
                "{$method} {$path} - Exception: " . $e->getMessage(),
                $context,
                $userId,
                $ipAddress,
                $userAgent
            );

            throw $e;
        }
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
     * Check if path should be skipped
     */
    private function shouldSkipLogging(string $path): bool
    {
        $skipPaths = [
            '/health',
            '/favicon.ico',
            '/robots.txt',
            '/sw.js',
            '/service-worker.js',
            '/manifest.json',
            '/.well-known',
        ];
        
        // Skip common static file extensions
        $skipExtensions = ['.ico', '.png', '.jpg', '.jpeg', '.gif', '.svg', '.css', '.js', '.woff', '.woff2', '.ttf', '.eot', '.map'];
        $pathLower = strtolower($path);
        foreach ($skipExtensions as $ext) {
            if (substr($pathLower, -strlen($ext)) === $ext) {
                return true;
            }
        }
        
        foreach ($skipPaths as $skipPath) {
            if ($path === $skipPath || strpos($path, $skipPath) === 0) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Sanitize request data
     */
    private function sanitizeRequestData(array $data): array
    {
        $sensitiveKeys = ['password', 'token', 'secret', 'api_key', 'access_token', 'refresh_token', 'csrf_token'];
        
        foreach ($data as $key => $value) {
            $lowerKey = strtolower((string)$key);
            foreach ($sensitiveKeys as $sensitive) {
                if (strpos($lowerKey, $sensitive) !== false) {
                    $data[$key] = '[REDACTED]';
                    break;
                }
            }
            
            // Recursively sanitize nested arrays
            if (is_array($value)) {
                $data[$key] = $this->sanitizeRequestData($value);
            }
        }
        
        return $data;
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

