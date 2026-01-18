<?php
declare(strict_types=1);

namespace Reut\Admin\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

/**
 * Admin Rate Limit Middleware
 * Stricter rate limiting for admin routes
 */
class AdminRateLimitMiddleware
{
    private bool $enabled;
    private int $authMaxRequests;
    private int $authWindowSeconds;
    private int $apiMaxRequests;
    private int $apiWindowSeconds;
    private string $storageDir;

    public function __construct()
    {
        $this->enabled = filter_var($_ENV['ADMIN_RATE_LIMIT_ENABLED'] ?? 'true', FILTER_VALIDATE_BOOLEAN);
        
        // Stricter limits for auth endpoints (login, register, refresh)
        $this->authMaxRequests = (int)($_ENV['ADMIN_RATE_LIMIT_AUTH_MAX'] ?? 10);
        $this->authWindowSeconds = (int)($_ENV['ADMIN_RATE_LIMIT_AUTH_WINDOW'] ?? 60);
        
        // Limits for protected API endpoints
        $this->apiMaxRequests = (int)($_ENV['ADMIN_RATE_LIMIT_API_MAX'] ?? 100);
        $this->apiWindowSeconds = (int)($_ENV['ADMIN_RATE_LIMIT_API_WINDOW'] ?? 60);
        
        $this->storageDir = sys_get_temp_dir() . '/reut_admin_rate_limit';
        
        // Create storage directory if it doesn't exist
        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0750, true);
        }
    }

    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        // Skip rate limiting if disabled
        if (!$this->enabled) {
            return $handler->handle($request);
        }

        // Determine if this is an auth endpoint
        $path = $request->getUri()->getPath();
        $isAuthEndpoint = strpos($path, '/api/auth/') !== false;
        
        $maxRequests = $isAuthEndpoint ? $this->authMaxRequests : $this->apiMaxRequests;
        $windowSeconds = $isAuthEndpoint ? $this->authWindowSeconds : $this->apiWindowSeconds;

        // Get client identifier (IP address)
        $clientId = $this->getClientIdentifier($request);
        $rateLimitKey = $this->getRateLimitKey($clientId, $isAuthEndpoint, $windowSeconds);

        // Check current rate limit
        if (!$this->checkRateLimit($rateLimitKey, $maxRequests)) {
            return $this->rateLimitExceededResponse($maxRequests, $windowSeconds);
        }

        // Increment request count
        $this->incrementRequestCount($rateLimitKey, $windowSeconds);

        return $handler->handle($request);
    }

    /**
     * Get client identifier (IP address)
     */
    private function getClientIdentifier(Request $request): string
    {
        // Check for forwarded IP (for proxies/load balancers)
        $forwardedFor = $request->getHeaderLine('X-Forwarded-For');
        if (!empty($forwardedFor)) {
            // Take the first IP in the chain
            $ips = explode(',', $forwardedFor);
            return trim($ips[0]);
        }

        // Check for real IP header
        $realIp = $request->getHeaderLine('X-Real-IP');
        if (!empty($realIp)) {
            return trim($realIp);
        }

        // Fallback to server remote address
        $serverParams = $request->getServerParams();
        return $serverParams['REMOTE_ADDR'] ?? 'unknown';
    }

    /**
     * Get rate limit storage key
     */
    private function getRateLimitKey(string $clientId, bool $isAuth, int $windowSeconds): string
    {
        $currentWindow = floor(time() / $windowSeconds);
        $prefix = $isAuth ? 'auth' : 'api';
        return md5($prefix . '_' . $clientId . '_' . $currentWindow);
    }

    /**
     * Check if rate limit is exceeded
     */
    private function checkRateLimit(string $key, int $maxRequests): bool
    {
        $filePath = $this->storageDir . '/' . $key . '.json';
        
        if (!file_exists($filePath)) {
            return true; // No previous requests in this window
        }

        $data = json_decode(file_get_contents($filePath), true);
        if (!is_array($data)) {
            return true;
        }

        $requestCount = $data['count'] ?? 0;
        return $requestCount < $maxRequests;
    }

    /**
     * Increment request count for current window
     */
    private function incrementRequestCount(string $key, int $windowSeconds): void
    {
        $filePath = $this->storageDir . '/' . $key . '.json';
        
        $data = [
            'count' => 1,
            'window_start' => time()
        ];

        if (file_exists($filePath)) {
            $existing = json_decode(file_get_contents($filePath), true);
            if (is_array($existing)) {
                $data['count'] = ($existing['count'] ?? 0) + 1;
            }
        }

        file_put_contents($filePath, json_encode($data), LOCK_EX);
        
        // Clean up old files (older than 2 windows)
        $this->cleanupOldFiles($windowSeconds);
    }

    /**
     * Clean up old rate limit files
     */
    private function cleanupOldFiles(int $windowSeconds): void
    {
        // Only cleanup occasionally (1% chance) to avoid overhead
        if (rand(1, 100) !== 1) {
            return;
        }

        $files = glob($this->storageDir . '/*.json');
        $cutoffTime = time() - (2 * $windowSeconds);

        foreach ($files as $file) {
            if (filemtime($file) < $cutoffTime) {
                @unlink($file);
            }
        }
    }

    /**
     * Return rate limit exceeded response
     */
    private function rateLimitExceededResponse(int $maxRequests, int $windowSeconds): Response
    {
        $response = new SlimResponse();
        $response->getBody()->write(json_encode([
            'error' => 'Rate limit exceeded',
            'message' => "Maximum {$maxRequests} requests per {$windowSeconds} seconds allowed"
        ], JSON_UNESCAPED_SLASHES));
        
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('X-RateLimit-Limit', (string)$maxRequests)
            ->withHeader('X-RateLimit-Window', (string)$windowSeconds)
            ->withHeader('Retry-After', (string)$windowSeconds)
            ->withStatus(429);
    }
}

