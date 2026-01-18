<?php
declare(strict_types=1);

namespace Reut\Admin\Middleware;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Http\Message\ResponseInterface as Response;
use Reut\Admin\Services\LogService;

/**
 * AdminLogMiddleware
 * Logs all admin API requests and responses
 */
class AdminLogMiddleware
{
    private $logService;
    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->logService = new LogService($config);
    }

    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        $startTime = microtime(true);
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();
        $ipAddress = $this->getClientIp($request);
        $userAgent = $request->getHeaderLine('User-Agent');
        
        // Get admin user from request attributes (set by AdminMiddleware)
        $adminUser = $request->getAttribute('admin_user');
        $userId = $adminUser['id'] ?? null;

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

        // Determine log type based on path
        $logType = $this->getLogType($path);
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
                $logType,
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
                'error',
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
                // X-Forwarded-For can contain multiple IPs, take the first one
                $ips = explode(',', $ip);
                $ip = trim($ips[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        $serverParams = $request->getServerParams();
        return $serverParams['REMOTE_ADDR'] ?? 'unknown';
    }

    /**
     * Determine if logging should be skipped for this path
     */
    private function shouldSkipLogging(string $path): bool
    {
        // Skip logging for static assets and UI routes
        $skipPaths = ['/admin/assets/', '/admin/login'];
        foreach ($skipPaths as $skipPath) {
            if (strpos($path, $skipPath) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Determine log type based on path
     */
    private function getLogType(string $path): string
    {
        if (strpos($path, '/api/routes') !== false || strpos($path, '/api/docs') !== false) {
            return 'api';
        }
        if (strpos($path, '/api-keys') !== false) {
            return 'api';
        }
        if (strpos($path, '/query') !== false) {
            return 'query';
        }
        if (strpos($path, '/migrations') !== false) {
            return 'migration';
        }
        if (strpos($path, '/models') !== false) {
            return 'action';
        }
        if (strpos($path, '/data') !== false) {
            return 'action';
        }
        if (strpos($path, '/admin-users') !== false) {
            return 'user';
        }
        if (strpos($path, '/users') !== false) {
            return 'user';
        }
        return 'request';
    }

    /**
     * Sanitize request data to remove sensitive information
     */
    private function sanitizeRequestData(array $data): array
    {
        $sensitiveKeys = ['password', 'token', 'secret', 'api_key', 'access_token', 'refresh_token', 'csrf_token'];
        
        foreach ($data as $key => $value) {
            // Convert key to string to handle numeric array keys
            $keyString = (string)$key;
            $lowerKey = strtolower($keyString);
            foreach ($sensitiveKeys as $sensitive) {
                if (strpos($lowerKey, $sensitive) !== false) {
                    $data[$key] = '[REDACTED]';
                    break;
                }
            }
            
            if (is_array($value)) {
                $data[$key] = $this->sanitizeRequestData($value);
            }
        }
        
        return $data;
    }

    /**
     * Sanitize stack trace (limit length)
     */
    private function sanitizeTrace(string $trace): string
    {
        // Limit trace to first 1000 characters
        return substr($trace, 0, 1000);
    }
}



