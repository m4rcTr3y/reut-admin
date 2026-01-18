<?php
declare(strict_types=1);

namespace Reut\Admin\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

/**
 * Admin CSRF Middleware
 * CSRF protection for admin API state-changing operations
 * Uses token-based validation compatible with JWT authentication
 */
class AdminCsrfMiddleware
{
    private bool $enabled;
    private string $tokenName;
    private int $tokenLength;
    private int $tokenLifetime;
    private string $storageDir;

    public function __construct()
    {
        $this->enabled = filter_var($_ENV['ADMIN_CSRF_ENABLED'] ?? 'true', FILTER_VALIDATE_BOOLEAN);
        $this->tokenName = $_ENV['ADMIN_CSRF_TOKEN_NAME'] ?? 'X-CSRF-Token';
        $this->tokenLength = (int)($_ENV['ADMIN_CSRF_TOKEN_LENGTH'] ?? 32);
        $this->tokenLifetime = (int)($_ENV['ADMIN_CSRF_TOKEN_LIFETIME'] ?? 3600); // 1 hour default
        $this->storageDir = sys_get_temp_dir() . '/reut_admin_csrf';
        
        // Create storage directory if it doesn't exist
        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0750, true);
        }
    }

    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        // Skip CSRF protection if disabled
        if (!$this->enabled) {
            return $handler->handle($request);
        }

        $method = $request->getMethod();

        // Only validate CSRF for state-changing methods
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            // Get user from request (set by AdminMiddleware)
            $user = $request->getAttribute('admin_user');
            
            if ($user && isset($user['id'])) {
                // Validate CSRF token for authenticated users
                if (!$this->validateCsrfToken($request, $user['id'])) {
                    return $this->csrfErrorResponse();
                }
            }
        }

        // Generate and attach CSRF token to response for authenticated users
        $response = $handler->handle($request);
        $user = $request->getAttribute('admin_user');
        
        if ($user && isset($user['id'])) {
            $token = $this->generateOrGetToken($user['id']);
            $response = $response->withHeader('X-CSRF-Token', $token);
        }

        return $response;
    }

    /**
     * Validate CSRF token from request
     */
    private function validateCsrfToken(Request $request, int $userId): bool
    {
        // Get token from header
        $token = $request->getHeaderLine($this->tokenName);
        
        if (empty($token)) {
            // Try alternative header name
            $token = $request->getHeaderLine('X-CSRF-Token');
        }
        
        if (empty($token)) {
            // Try body parameter
            $body = $request->getParsedBody();
            if (is_array($body) && isset($body['csrf_token'])) {
                $token = (string)$body['csrf_token'];
            }
        }

        if (empty($token)) {
            return false;
        }

        // Get stored token for user
        $storedToken = $this->getStoredToken($userId);
        
        if (empty($storedToken)) {
            return false;
        }

        // Compare tokens using timing-safe comparison
        return hash_equals($storedToken, $token);
    }

    /**
     * Generate or get existing CSRF token for user
     */
    private function generateOrGetToken(int $userId): string
    {
        $filePath = $this->storageDir . '/' . md5('user_' . $userId) . '.json';
        
        // Check if token exists and is valid
        if (file_exists($filePath)) {
            $data = json_decode(file_get_contents($filePath), true);
            if (is_array($data) && isset($data['token']) && isset($data['expires'])) {
                if ($data['expires'] > time()) {
                    return $data['token'];
                }
            }
        }

        // Generate new token
        $token = bin2hex(random_bytes($this->tokenLength / 2));
        $expires = time() + $this->tokenLifetime;
        
        file_put_contents($filePath, json_encode([
            'token' => $token,
            'expires' => $expires,
            'user_id' => $userId
        ]), LOCK_EX);
        
        // Clean up old tokens
        $this->cleanupOldTokens();
        
        return $token;
    }

    /**
     * Get stored CSRF token for user
     */
    private function getStoredToken(int $userId): ?string
    {
        $filePath = $this->storageDir . '/' . md5('user_' . $userId) . '.json';
        
        if (!file_exists($filePath)) {
            return null;
        }

        $data = json_decode(file_get_contents($filePath), true);
        if (!is_array($data) || !isset($data['token']) || !isset($data['expires'])) {
            return null;
        }

        // Check if token expired
        if ($data['expires'] <= time()) {
            @unlink($filePath);
            return null;
        }

        return $data['token'];
    }

    /**
     * Clean up expired CSRF tokens
     */
    private function cleanupOldTokens(): void
    {
        // Only cleanup occasionally (1% chance) to avoid overhead
        if (rand(1, 100) !== 1) {
            return;
        }

        $files = glob($this->storageDir . '/*.json');
        $now = time();

        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if (is_array($data) && isset($data['expires']) && $data['expires'] <= $now) {
                @unlink($file);
            }
        }
    }

    /**
     * Return CSRF error response
     */
    private function csrfErrorResponse(): Response
    {
        $response = new SlimResponse();
        $response->getBody()->write(json_encode([
            'error' => 'CSRF token validation failed',
            'message' => 'Invalid or missing CSRF token. Please refresh the page and try again.'
        ], JSON_UNESCAPED_SLASHES));
        
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(403);
    }
}

