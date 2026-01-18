<?php
declare(strict_types=1);

namespace Reut\Admin\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Reut\Admin\AdminAuth;
use Reut\Admin\Models\AdminUser;
use Reut\Admin\Services\PasswordValidator;
use Reut\Admin\Services\SessionService;
use Reut\Support\ProjectPath;

class AuthController
{
    private $adminAuth;
    private $sessionService;
    private $config;

    public function __construct()
    {
        $projectRoot = ProjectPath::root();
        require $projectRoot . '/config.php';
        $this->config = $config ?? [];
        $this->adminAuth = new AdminAuth($this->config);
        $this->sessionService = new SessionService($this->config);
    }

    public function login(Request $request, Response $response): Response
    {
        // Try to get parsed body first (if BodyParsingMiddleware is enabled)
        $data = $request->getParsedBody();
        
        // If parsed body is null or not an array, try to parse JSON from raw body
        if (!is_array($data)) {
            $bodyContents = $request->getBody()->getContents();
            if (!empty($bodyContents)) {
                $data = json_decode($bodyContents, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $data = [];
                }
            } else {
                $data = [];
            }
        }
        
        $email = isset($data['email']) ? trim((string)$data['email']) : '';
        $password = isset($data['password']) ? trim((string)$data['password']) : '';

        if (empty($email) || empty($password)) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Email and password are required'
            ], JSON_UNESCAPED_SLASHES));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        // Get client IP address for lockout tracking
        $ipAddress = $this->getClientIp($request);

        $result = $this->adminAuth->login($email, $password, $ipAddress);

        if (!$result['success']) {
            $statusCode = isset($result['locked_until']) ? 423 : 401; // 423 = Locked
            $response->getBody()->write(json_encode($result, JSON_UNESCAPED_SLASHES));
            return $response->withStatus($statusCode)->withHeader('Content-Type', 'application/json');
        }

        // Create session for token tracking
        $token = $result['token'];
        $refreshToken = $result['refreshToken'] ?? null;
        $tokenHash = hash('sha256', $token);
        $refreshTokenHash = $refreshToken ? hash('sha256', $refreshToken) : null;
        $userAgent = $request->getHeaderLine('User-Agent');
        
        $this->sessionService->createSession(
            $result['user']['id'],
            $tokenHash,
            $refreshTokenHash,
            $ipAddress,
            $userAgent,
            86400 // 24 hours
        );

        // Generate CSRF token for the user
        $csrfToken = $this->generateCsrfTokenForUser($result['user']['id']);
        
        $result['csrfToken'] = $csrfToken;
        $response = $response->withHeader('X-CSRF-Token', $csrfToken);
        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Get client IP address from request
     */
    private function getClientIp(Request $request): string
    {
        // Check for forwarded IP (for proxies/load balancers)
        $forwardedFor = $request->getHeader('X-Forwarded-For');
        if (!empty($forwardedFor) && !empty($forwardedFor[0])) {
            $ips = explode(',', $forwardedFor[0]);
            return trim($ips[0]);
        }

        $realIp = $request->getHeader('X-Real-Ip');
        if (!empty($realIp) && !empty($realIp[0])) {
            return trim($realIp[0]);
        }

        // Fallback to server remote address
        $serverParams = $request->getServerParams();
        return $serverParams['REMOTE_ADDR'] ?? '';
    }

    /**
     * Generate CSRF token for user (helper method)
     */
    private function generateCsrfTokenForUser(int $userId): string
    {
        $storageDir = sys_get_temp_dir() . '/reut_admin_csrf';
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0750, true);
        }
        
        $filePath = $storageDir . '/' . md5('user_' . $userId) . '.json';
        $token = bin2hex(random_bytes(16)); // 32 character token
        $expires = time() + 3600; // 1 hour
        
        file_put_contents($filePath, json_encode([
            'token' => $token,
            'expires' => $expires,
            'user_id' => $userId
        ]), LOCK_EX);
        
        return $token;
    }

    public function register(Request $request, Response $response): Response
    {
        // Check if any admin users already exist
        $adminUserModel = new AdminUser($this->config);
        $adminUserModel->connect();
        try {
            $existingAdmins = $adminUserModel->findAll();
            if ($existingAdmins && !empty($existingAdmins->results)) {
                // Registration is disabled after first admin is created
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Registration is disabled. Please contact an administrator to create new accounts.'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
            }
        } catch (\Exception $e) {
            // If table doesn't exist yet, allow registration (first-time setup)
            // Continue with registration
        }

        // Try to get parsed body first (if BodyParsingMiddleware is enabled)
        $data = $request->getParsedBody();
        
        // If parsed body is null or not an array, try to parse JSON from raw body
        if (!is_array($data)) {
            $bodyContents = $request->getBody()->getContents();
            if (!empty($bodyContents)) {
                $data = json_decode($bodyContents, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $data = [];
                }
            } else {
                $data = [];
            }
        }
        
        $username = isset($data['username']) ? trim((string)$data['username']) : '';
        $email = isset($data['email']) ? trim((string)$data['email']) : '';
        $password = isset($data['password']) ? trim((string)$data['password']) : '';
        $role = isset($data['role']) ? trim((string)$data['role']) : 'admin';

        if (empty($username) || empty($email) || empty($password)) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Username, email, and password are required'
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        // Validate password strength (also done in AdminAuth, but provide early feedback)
        $passwordValidator = new PasswordValidator();
        $validation = $passwordValidator->validate($password);
        if (!$validation['valid']) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Password does not meet requirements: ' . implode(', ', $validation['errors']),
                'requirements' => $passwordValidator->getRequirements()
            ], JSON_UNESCAPED_SLASHES));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $result = $this->adminAuth->register($username, $email, $password, $role);

        if (!$result['success']) {
            $response->getBody()->write(json_encode($result));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function refresh(Request $request, Response $response): Response
    {
        // Try to get parsed body first (if BodyParsingMiddleware is enabled)
        $data = $request->getParsedBody();
        
        // If parsed body is null or not an array, try to parse JSON from raw body
        if (!is_array($data)) {
            $bodyContents = $request->getBody()->getContents();
            if (!empty($bodyContents)) {
                $data = json_decode($bodyContents, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $data = [];
                }
            } else {
                $data = [];
            }
        }
        
        $refreshToken = isset($data['refreshToken']) ? trim((string)$data['refreshToken']) : '';
        $userId = isset($data['userId']) ? (int)$data['userId'] : 0;

        if (empty($refreshToken) || empty($userId)) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Refresh token and user ID are required'
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $result = $this->adminAuth->refreshToken($refreshToken, (int)$userId);

        if (!$result) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Invalid refresh token'
            ]));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }

        // Generate new CSRF token for the user (rotate on refresh)
        $csrfToken = $this->generateCsrfTokenForUser((int)$userId);
        $result['csrfToken'] = $csrfToken;
        
        $response = $response->withHeader('X-CSRF-Token', $csrfToken);
        $response->getBody()->write(json_encode([
            'success' => true,
            ...$result
        ], JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json');
    }
}



