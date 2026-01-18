<?php
declare(strict_types=1);

namespace Reut\Admin;

use Reut\Admin\Models\AdminUser;
use Reut\Admin\Models\LoginAttempt;
use Reut\Admin\Services\PasswordValidator;
use Reut\Admin\Services\PermissionService;
use Reut\Admin\Services\SessionService;
use Reut\Middleware\JwtAuth;

/**
 * Admin Authentication Handler
 * Handles admin login, registration, and JWT token management
 */
class AdminAuth
{
    private $adminUserModel;
    private $jwtAuth;
    private $sessionService;
    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->adminUserModel = new AdminUser($config);
        $this->jwtAuth = new JwtAuth($config);
        $this->sessionService = new SessionService($config);
    }

    /**
     * Register a new admin user
     */
    public function register(string $username, string $email, string $password, string $role = 'admin'): array
    {
        // Validate password strength
        $passwordValidator = new PasswordValidator();
        $validation = $passwordValidator->validate($password);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'error' => 'Password does not meet requirements: ' . implode(', ', $validation['errors'])
            ];
        }

        // Check if user already exists
        $existingModel = $this->adminUserModel->findOne(['email' => $email]);
        $existing = $existingModel->results ?? null;
        if ($existing && !empty($existing)) {
            return ['success' => false, 'error' => 'Email already registered'];
        }

        $existingModel = $this->adminUserModel->findOne(['username' => $username]);
        $existing = $existingModel->results ?? null;
        if ($existing && !empty($existing)) {
            return ['success' => false, 'error' => 'Username already taken'];
        }

        // Hash password
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        // Validate role
        $permissionService = new \Reut\Admin\Services\PermissionService();
        if (!empty($role) && !$permissionService::isValidRole($role)) {
            return ['success' => false, 'error' => 'Invalid role specified'];
        }
        
        // Set default role if not provided or invalid
        if (empty($role) || !$permissionService::isValidRole($role)) {
            $role = $permissionService::getDefaultRole();
        }

        // Create admin user
        $data = [
            'username' => $username,
            'email' => $email,
            'password' => $hashedPassword,
            'role' => $role
        ];

        $result = $this->adminUserModel->addOne($data);

        if ($result) {
            // Get the inserted ID from PDO
            $this->adminUserModel->connect();
            $insertId = $this->adminUserModel->pdo->lastInsertId();
            
            if ($insertId) {
                // Generate JWT token
                $token = $this->jwtAuth->generateToken($insertId, 86400); // 24 hours
                $refreshToken = $this->jwtAuth->generateRefreshToken($insertId);

                return [
                    'success' => true,
                    'user' => [
                        'id' => (int)$insertId,
                        'username' => $username,
                        'email' => $email,
                        'role' => $role
                    ],
                    'token' => $token,
                    'refreshToken' => $refreshToken
                ];
            }
        }

        return ['success' => false, 'error' => 'Failed to create admin user'];
    }

    /**
     * Login admin user
     */
    public function login(string $email, string $password, string $ipAddress = ''): array
    {
        // Check if account is locked
        $lockoutCheck = $this->checkLockout($email, $ipAddress);
        if (!$lockoutCheck['allowed']) {
            return [
                'success' => false,
                'error' => $lockoutCheck['message'],
                'locked_until' => $lockoutCheck['locked_until'] ?? null
            ];
        }

        $userModel = $this->adminUserModel->findOne(['email' => $email]);
        $user = $userModel->results ?? null;

        if (!$user || empty($user)) {
            $this->recordFailedAttempt($email, $ipAddress);
            return ['success' => false, 'error' => 'Invalid credentials'];
        }

        if (!password_verify($password, $user['password'])) {
            $this->recordFailedAttempt($email, $ipAddress);
            return ['success' => false, 'error' => 'Invalid credentials'];
        }

        // Successful login - clear failed attempts
        $this->clearFailedAttempts($email, $ipAddress);

        // Generate tokens
        $token = $this->jwtAuth->generateToken($user['id'], 86400); // 24 hours
        $refreshToken = $this->jwtAuth->generateRefreshToken($user['id']);

        return [
            'success' => true,
            'user' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'role' => $user['role']
            ],
            'token' => $token,
            'refreshToken' => $refreshToken
        ];
    }

    /**
     * Check if account is locked due to failed attempts
     */
    private function checkLockout(string $email, string $ipAddress): array
    {
        $loginAttemptModel = new LoginAttempt($this->config);
        $loginAttemptModel->connect();

        // Check by email first
        $attemptModel = $loginAttemptModel->findOne(['email' => $email]);
        $attempt = $attemptModel->results ?? null;

        // If no email-based attempt, check by IP
        if (!$attempt || empty($attempt)) {
            $attemptModel = $loginAttemptModel->findOne(['ip_address' => $ipAddress]);
            $attempt = $attemptModel->results ?? null;
        }

        if ($attempt && !empty($attempt)) {
            // Check if account is locked
            if (!empty($attempt['locked_until'])) {
                $lockedUntil = strtotime($attempt['locked_until']);
                $now = time();
                
                if ($lockedUntil > $now) {
                    $minutesRemaining = ceil(($lockedUntil - $now) / 60);
                    return [
                        'allowed' => false,
                        'message' => "Account is locked due to too many failed login attempts. Please try again in {$minutesRemaining} minute(s).",
                        'locked_until' => $attempt['locked_until']
                    ];
                } else {
                    // Lockout expired, clear it
                    $this->clearFailedAttempts($email, $ipAddress);
                }
            }
        }

        return ['allowed' => true];
    }

    /**
     * Record a failed login attempt
     */
    private function recordFailedAttempt(string $email, string $ipAddress): void
    {
        $loginAttemptModel = new LoginAttempt($this->config);
        $loginAttemptModel->connect();

        $maxAttempts = 5;
        $lockoutDuration = 15 * 60; // 15 minutes in seconds

        // Try to find existing attempt by email
        $attemptModel = $loginAttemptModel->findOne(['email' => $email]);
        $attempt = $attemptModel->results ?? null;

        // If not found by email, try by IP
        if (!$attempt || empty($attempt)) {
            $attemptModel = $loginAttemptModel->findOne(['ip_address' => $ipAddress]);
            $attempt = $attemptModel->results ?? null;
        }

        if ($attempt && !empty($attempt)) {
            // Update existing attempt
            $newAttempts = ($attempt['attempts'] ?? 0) + 1;
            $updateData = ['attempts' => $newAttempts];

            // Lock account if max attempts reached
            if ($newAttempts >= $maxAttempts) {
                $updateData['locked_until'] = date('Y-m-d H:i:s', time() + $lockoutDuration);
            }

            $loginAttemptModel->update($updateData, ['id' => $attempt['id']]);
        } else {
            // Create new attempt record
            $loginAttemptModel->addOne([
                'email' => $email,
                'ip_address' => $ipAddress,
                'attempts' => 1
            ]);
        }
    }

    /**
     * Clear failed login attempts (on successful login)
     */
    private function clearFailedAttempts(string $email, string $ipAddress): void
    {
        $loginAttemptModel = new LoginAttempt($this->config);
        $loginAttemptModel->connect();

        // Delete attempts by email
        $loginAttemptModel->delete(['email' => $email]);
        
        // Also delete attempts by IP (in case email wasn't used)
        if (!empty($ipAddress)) {
            $loginAttemptModel->delete(['ip_address' => $ipAddress]);
        }
    }

    /**
     * Validate admin token
     */
    public function validateToken(string $token): ?array
    {
        $decoded = $this->jwtAuth->validateToken($token);
        if (!$decoded) {
            return null;
        }

        $userId = $decoded->sub ?? null;
        if (!$userId) {
            return null;
        }

        $userModel = $this->adminUserModel->findOne(['id' => $userId]);
        $user = $userModel->results ?? null;
        if (!$user || empty($user)) {
            return null;
        }

        return [
            'id' => $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'role' => $user['role']
        ];
    }

    /**
     * Refresh token with rotation
     * Revokes old refresh token and issues new ones
     */
    public function refreshToken(string $refreshToken, int $userId): ?array
    {
        if (!$this->jwtAuth->validateRefreshToken($userId, $refreshToken)) {
            return null;
        }

        // Get session service for token rotation
        $sessionService = new \Reut\Admin\Services\SessionService($this->config);
        
        // Hash the old refresh token to find the session
        $oldRefreshTokenHash = hash('sha256', $refreshToken);
        $session = $sessionService->getSessionByRefreshToken($oldRefreshTokenHash);
        
        if (!$session) {
            // Session not found, token may have been revoked
            return null;
        }

        // Generate new tokens
        $token = $this->jwtAuth->generateToken($userId, 86400);
        $newRefreshToken = $this->jwtAuth->generateRefreshToken($userId);
        
        // Hash the new tokens
        $newTokenHash = hash('sha256', $token);
        $newRefreshTokenHash = hash('sha256', $newRefreshToken);
        
        // Rotate tokens in session (revokes old, sets new)
        $rotated = $sessionService->rotateSessionTokens(
            $oldRefreshTokenHash,
            $newTokenHash,
            $newRefreshTokenHash,
            86400 // 24 hours
        );
        
        if (!$rotated) {
            return null;
        }

        return [
            'token' => $token,
            'refreshToken' => $newRefreshToken
        ];
    }
}

