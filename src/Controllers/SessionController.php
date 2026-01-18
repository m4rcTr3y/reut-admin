<?php
declare(strict_types=1);

namespace Reut\Admin\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Reut\Admin\Services\SessionService;
use Reut\Admin\Services\ErrorSanitizer;
use Reut\Support\ProjectPath;

class SessionController
{
    private $sessionService;
    private $config;

    public function __construct()
    {
        $projectRoot = ProjectPath::root();
        require $projectRoot . '/config.php';
        $this->config = $config ?? [];
        $this->sessionService = new SessionService($this->config);
    }

    /**
     * Get all active sessions for the current user
     */
    public function getSessions(Request $request, Response $response): Response
    {
        try {
            $user = $request->getAttribute('admin_user');
            if (!$user || !isset($user['id'])) {
                $response->getBody()->write(json_encode([
                    'error' => ErrorSanitizer::getGenericMessage('unauthorized')
                ]));
                return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
            }

            $sessions = $this->sessionService->getUserSessions($user['id']);
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'sessions' => $sessions,
                'count' => count($sessions)
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => ErrorSanitizer::sanitize($e, 'getSessions')
            ]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    /**
     * Revoke a specific session
     */
    public function revokeSession(Request $request, Response $response, array $args): Response
    {
        try {
            $user = $request->getAttribute('admin_user');
            if (!$user || !isset($user['id'])) {
                $response->getBody()->write(json_encode([
                    'error' => ErrorSanitizer::getGenericMessage('unauthorized')
                ]));
                return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
            }

            $sessionId = $args['sessionId'] ?? 0;
            if (empty($sessionId)) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => ErrorSanitizer::getGenericMessage('validation')
                ]));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }

            // Revoke session by ID (verifies ownership)
            $revoked = $this->sessionService->revokeSessionById((int)$sessionId, $user['id']);
            
            if (!$revoked) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => ErrorSanitizer::getGenericMessage('not_found')
                ]));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
            }

            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Session revoked successfully'
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => ErrorSanitizer::sanitize($e, 'revokeSession')
            ]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    /**
     * Revoke all sessions for the current user (except current)
     */
    public function revokeAllSessions(Request $request, Response $response): Response
    {
        try {
            $user = $request->getAttribute('admin_user');
            if (!$user || !isset($user['id'])) {
                $response->getBody()->write(json_encode([
                    'error' => ErrorSanitizer::getGenericMessage('unauthorized')
                ]));
                return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
            }

            // Get current token to exclude it
            $authHeader = $request->getHeader('Authorization');
            $currentToken = '';
            if (!empty($authHeader[0])) {
                $currentToken = str_replace('Bearer ', '', $authHeader[0]);
            }

            // Revoke all sessions
            $revoked = $this->sessionService->revokeAllUserSessions($user['id']);
            
            // Recreate current session if we have a token
            if ($currentToken) {
                $tokenHash = hash('sha256', $currentToken);
                $ipAddress = $this->getClientIp($request);
                $userAgent = $request->getHeaderLine('User-Agent');
                $this->sessionService->createSession(
                    $user['id'],
                    $tokenHash,
                    null,
                    $ipAddress,
                    $userAgent,
                    3600 // 1 hour
                );
            }

            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => "Revoked {$revoked} session(s)",
                'revoked' => $revoked
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => ErrorSanitizer::sanitize($e, 'revokeAllSessions')
            ]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    /**
     * Get client IP address from request
     */
    private function getClientIp(Request $request): string
    {
        $forwardedFor = $request->getHeaderLine('X-Forwarded-For');
        if (!empty($forwardedFor)) {
            $ips = explode(',', $forwardedFor);
            return trim($ips[0]);
        }

        $realIp = $request->getHeaderLine('X-Real-IP');
        if (!empty($realIp)) {
            return trim($realIp);
        }

        $serverParams = $request->getServerParams();
        return $serverParams['REMOTE_ADDR'] ?? '';
    }
}

