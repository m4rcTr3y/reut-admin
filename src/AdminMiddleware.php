<?php
declare(strict_types=1);

namespace Reut\Admin;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Http\Message\ResponseInterface as Response;
use Reut\Admin\Services\SessionService;
use Slim\Psr7\Response as SlimResponse;

/**
 * Admin Middleware
 * Protects admin routes with JWT authentication
 */
class AdminMiddleware
{
    private $adminAuth;
    private $sessionService;
    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->adminAuth = new AdminAuth($config);
        $this->sessionService = new SessionService($config);
    }

    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        $response = new SlimResponse();
        $authHeader = $request->getHeader('Authorization');

        if (!$authHeader || empty($authHeader[0])) {
            $response->getBody()->write(json_encode([
                'error' => 'Authentication required',
                'action' => 'login'
            ]));
            return $response->withStatus(401)
                ->withHeader('Content-Type', 'application/json');
        }

        $token = str_replace('Bearer ', '', $authHeader[0]);
        
        // Check if token is revoked
        $tokenHash = hash('sha256', $token);
        if ($this->sessionService->isTokenRevoked($tokenHash)) {
            $response->getBody()->write(json_encode([
                'error' => 'Token has been revoked',
                'action' => 'login'
            ]));
            return $response->withStatus(401)
                ->withHeader('Content-Type', 'application/json');
        }
        
        $user = $this->adminAuth->validateToken($token);

        if (!$user) {
            $response->getBody()->write(json_encode([
                'error' => 'Invalid or expired token',
                'action' => 'refresh_token'
            ]));
            return $response->withStatus(401)
                ->withHeader('Content-Type', 'application/json');
        }
        
        // Update session activity
        $this->sessionService->updateActivity($tokenHash);

        // Add user to request attributes
        $request = $request->withAttribute('admin_user', $user);

        return $handler->handle($request);
    }
}



