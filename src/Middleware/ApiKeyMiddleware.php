<?php
declare(strict_types=1);

namespace Reut\Admin\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Reut\Admin\Models\ApiKey;
use Reut\Support\ProjectPath;
use Slim\Psr7\Response as SlimResponse;

/**
 * API Key Authentication Middleware
 * Validates API keys from X-API-Key header or Authorization: ApiKey <key>
 * 
 * This middleware is automatically registered when AdminController is registered.
 * It can be enabled/disabled via API_KEY_AUTH_ENABLED environment variable.
 */
class ApiKeyMiddleware implements MiddlewareInterface
{
    private array $config;
    private ?ApiKey $apiKeyModel = null;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        // Check if API key authentication is enabled
        $enabled = filter_var(
            $_ENV['API_KEY_AUTH_ENABLED'] ?? 'true',
            FILTER_VALIDATE_BOOLEAN
        );

        if (!$enabled) {
            return $handler->handle($request);
        }

        // Skip API key validation for admin routes (they use JWT)
        $path = $request->getUri()->getPath();
        if (strpos($path, '/admin/') === 0) {
            return $handler->handle($request);
        }

        // Skip for auth routes (login, register, etc.)
        if (strpos($path, '/auth/') === 0 || strpos($path, '/login') === 0 || strpos($path, '/register') === 0) {
            return $handler->handle($request);
        }

        // Skip for public routes (health check, docs, schema)
        if (in_array($path, ['/health', '/docs', '/schema']) || strpos($path, '/docs/') === 0) {
            return $handler->handle($request);
        }

        // Get API key from headers
        $apiKey = $this->extractApiKey($request);

        if (!$apiKey) {
            // No API key provided - check if route requires authentication
            // For now, we'll allow requests without API keys (optional auth)
            // Routes that require auth should use JwtAuth middleware
            return $handler->handle($request);
        }

        // Validate API key
        $keyData = $this->validateApiKey($apiKey, $request);

        if (!$keyData) {
            // Invalid API key - return 401
            return $this->unauthorizedResponse('Invalid API key');
        }

        // Check if key is active
        if (!($keyData['is_active'] ?? false)) {
            return $this->unauthorizedResponse('API key is inactive');
        }

        // Check expiration
        if (isset($keyData['expires_at']) && $keyData['expires_at']) {
            $expiresAt = strtotime($keyData['expires_at']);
            if ($expiresAt && $expiresAt < time()) {
                return $this->unauthorizedResponse('API key has expired');
            }
        }

        // Check IP restrictions
        $clientIp = $this->getClientIp($request);
        if (!empty($keyData['allowed_ips'])) {
            $allowedIps = json_decode($keyData['allowed_ips'], true);
            if (is_array($allowedIps) && !empty($allowedIps)) {
                if (!in_array($clientIp, $allowedIps)) {
                    return $this->unauthorizedResponse('IP address not allowed');
                }
            }
        }

        // Update last_used_at
        $this->updateLastUsed($keyData['id']);

        // Add API key data to request attributes for use in routes
        $request = $request->withAttribute('api_key', $keyData);
        $request = $request->withAttribute('api_key_id', $keyData['id']);
        $request = $request->withAttribute('api_key_permissions', json_decode($keyData['permissions'] ?? '[]', true));

        return $handler->handle($request);
    }

    /**
     * Extract API key from request headers
     */
    private function extractApiKey(Request $request): ?string
    {
        // Try X-API-Key header first
        $apiKeyHeader = $request->getHeaderLine('X-API-Key');
        if (!empty($apiKeyHeader)) {
            return trim($apiKeyHeader);
        }

        // Try Authorization: ApiKey <key>
        $authHeader = $request->getHeaderLine('Authorization');
        if (!empty($authHeader)) {
            if (preg_match('/^ApiKey\s+(.+)$/i', $authHeader, $matches)) {
                return trim($matches[1]);
            }
        }

        return null;
    }

    /**
     * Validate API key against database
     */
    private function validateApiKey(string $apiKey, Request $request): ?array
    {
        try {
            if (!$this->apiKeyModel) {
                $this->apiKeyModel = new ApiKey($this->config);
                $this->apiKeyModel->connect();
            }

            // Query the database for the API key
            $result = $this->apiKeyModel->sqlQuery(
                "SELECT id, name, `key`, permissions, allowed_ips, rate_limit, is_active, last_used_at, expires_at, created_at 
                 FROM api_keys 
                 WHERE `key` = :key 
                 LIMIT 1",
                ['key' => $apiKey]
            );

            if (is_string($result)) {
                // Query error
                error_log("API key validation error: " . $result);
                return null;
            }

            if (empty($result) || !is_array($result)) {
                return null;
            }

            return $result[0];
        } catch (\Exception $e) {
            error_log("API key validation exception: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get client IP address
     */
    private function getClientIp(Request $request): string
    {
        // Check for forwarded IP (when behind proxy)
        $forwardedFor = $request->getHeaderLine('X-Forwarded-For');
        if (!empty($forwardedFor)) {
            $ips = explode(',', $forwardedFor);
            return trim($ips[0]);
        }

        $realIp = $request->getHeaderLine('X-Real-IP');
        if (!empty($realIp)) {
            return trim($realIp);
        }

        // Fallback to server remote address
        $serverParams = $request->getServerParams();
        return $serverParams['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * Update last_used_at timestamp
     */
    private function updateLastUsed(int $keyId): void
    {
        try {
            if (!$this->apiKeyModel) {
                return;
            }

            $this->apiKeyModel->sqlQuery(
                "UPDATE api_keys SET last_used_at = NOW() WHERE id = :id",
                ['id' => $keyId]
            );
        } catch (\Exception $e) {
            // Silently fail - don't break the request if update fails
            error_log("Failed to update API key last_used_at: " . $e->getMessage());
        }
    }

    /**
     * Return unauthorized response
     */
    private function unauthorizedResponse(string $message = 'Unauthorized'): Response
    {
        $response = new SlimResponse();
        $response->getBody()->write(json_encode([
            'error' => true,
            'message' => $message,
            'code' => 'API_KEY_INVALID'
        ], JSON_UNESCAPED_SLASHES));
        
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(401);
    }
}

