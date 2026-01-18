<?php
declare(strict_types=1);

namespace Reut\Admin\Services;

use Reut\Admin\Models\AdminSession;

/**
 * Session Service
 * Manages admin user sessions and token revocation
 */
class SessionService
{
    private $sessionModel;
    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->sessionModel = new AdminSession($config);
    }

    /**
     * Create a new session
     */
    public function createSession(
        int $userId,
        string $tokenHash,
        ?string $refreshTokenHash = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?int $expiresIn = null
    ): int {
        $this->sessionModel->connect();
        
        $expiresAt = null;
        if ($expiresIn) {
            $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);
        }
        
        $result = $this->sessionModel->addOne([
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'refresh_token_hash' => $refreshTokenHash,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'created_at' => date('Y-m-d H:i:s'),
            'last_activity' => date('Y-m-d H:i:s'),
            'expires_at' => $expiresAt
        ]);
        
        // Check if addOne failed
        if ($result === false || is_string($result)) {
            throw new \RuntimeException('Failed to create session: ' . ($result ?: 'Unknown error'));
        }
        
        // Get the last insert ID
        $sessionId = (int)$this->sessionModel->pdo->lastInsertId();
        
        if ($sessionId === 0) {
            throw new \RuntimeException('Failed to get session ID after creation');
        }
        
        return $sessionId;
    }

    /**
     * Update last activity for a session
     */
    public function updateActivity(string $tokenHash): void
    {
        $this->sessionModel->connect();
        $this->sessionModel->update(
            ['last_activity' => date('Y-m-d H:i:s')],
            ['token_hash' => $tokenHash]
        );
    }

    /**
     * Check if token is revoked
     */
    public function isTokenRevoked(string $tokenHash): bool
    {
        $this->sessionModel->connect();
        $session = $this->sessionModel->findOne([
            'token_hash' => $tokenHash
        ])->results;
        
        // If session doesn't exist, token is considered revoked
        return $session === null;
    }

    /**
     * Revoke a session (token)
     */
    public function revokeSession(string $tokenHash): bool
    {
        $this->sessionModel->connect();
        $deleted = $this->sessionModel->delete(['token_hash' => $tokenHash]);
        return $deleted > 0;
    }

    /**
     * Revoke a session by ID (for a specific user)
     */
    public function revokeSessionById(int $sessionId, int $userId): bool
    {
        $this->sessionModel->connect();
        $session = $this->sessionModel->findOne(['id' => $sessionId])->results;
        
        if (!$session || $session['user_id'] != $userId) {
            return false; // Session doesn't exist or doesn't belong to user
        }
        
        $deleted = $this->sessionModel->delete(['id' => $sessionId]);
        return $deleted > 0;
    }

    /**
     * Revoke all sessions for a user
     */
    public function revokeAllUserSessions(int $userId): int
    {
        $this->sessionModel->connect();
        $deleted = $this->sessionModel->delete(['user_id' => $userId]);
        return $deleted;
    }

    /**
     * Get all active sessions for a user
     */
    public function getUserSessions(int $userId): array
    {
        $this->sessionModel->connect();
        $sessions = $this->sessionModel->find(['user_id' => $userId])->results;
        
        if (!is_array($sessions)) {
            return [];
        }
        
        // Filter out expired sessions
        $now = time();
        $activeSessions = [];
        
        foreach ($sessions as $session) {
            if ($session['expires_at']) {
                $expires = strtotime($session['expires_at']);
                if ($expires < $now) {
                    continue; // Skip expired
                }
            }
            
            // Don't expose token hashes to frontend
            unset($session['token_hash']);
            unset($session['refresh_token_hash']);
            
            $activeSessions[] = $session;
        }
        
        return $activeSessions;
    }

    /**
     * Clean up expired sessions
     */
    public function cleanupExpiredSessions(): int
    {
        $this->sessionModel->connect();
        $now = date('Y-m-d H:i:s');
        
        // Delete sessions where expires_at < now
        $expired = $this->sessionModel->find([
            'expires_at' => ['<', $now]
        ])->results;
        
        if (!is_array($expired)) {
            return 0;
        }
        
        $deleted = 0;
        foreach ($expired as $session) {
            $this->sessionModel->delete(['id' => $session['id']]);
            $deleted++;
        }
        
        return $deleted;
    }

    /**
     * Get session by token hash
     */
    public function getSessionByToken(string $tokenHash): ?array
    {
        $this->sessionModel->connect();
        $session = $this->sessionModel->findOne(['token_hash' => $tokenHash])->results;
        return $session ?: null;
    }

    /**
     * Get session by refresh token hash
     */
    public function getSessionByRefreshToken(string $refreshTokenHash): ?array
    {
        $this->sessionModel->connect();
        $session = $this->sessionModel->findOne(['refresh_token_hash' => $refreshTokenHash])->results;
        return $session ?: null;
    }

    /**
     * Rotate tokens for a session (update token hashes)
     * Used during refresh token rotation
     */
    public function rotateSessionTokens(
        string $oldRefreshTokenHash,
        string $newTokenHash,
        string $newRefreshTokenHash,
        ?int $expiresIn = null
    ): bool {
        $this->sessionModel->connect();
        
        $session = $this->getSessionByRefreshToken($oldRefreshTokenHash);
        if (!$session) {
            return false;
        }
        
        $updateData = [
            'token_hash' => $newTokenHash,
            'refresh_token_hash' => $newRefreshTokenHash,
            'last_activity' => date('Y-m-d H:i:s')
        ];
        
        if ($expiresIn) {
            $updateData['expires_at'] = date('Y-m-d H:i:s', time() + $expiresIn);
        }
        
        $this->sessionModel->update($updateData, ['id' => $session['id']]);
        return true;
    }

    /**
     * Get all sessions (for admin view)
     */
    public function getAllSessions(): array
    {
        $this->sessionModel->connect();
        $sessions = $this->sessionModel->findAll()->results;
        
        if (!is_array($sessions)) {
            return [];
        }
        
        // Remove sensitive data
        foreach ($sessions as &$session) {
            unset($session['token_hash']);
            unset($session['refresh_token_hash']);
        }
        
        return $sessions;
    }
}

