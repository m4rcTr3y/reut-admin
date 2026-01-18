<?php
declare(strict_types=1);

namespace Reut\Admin\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Reut\Admin\Services\PermissionService;
use Slim\Psr7\Response as SlimResponse;

/**
 * Role Middleware
 * Checks permissions based on user role
 */
class RoleMiddleware
{
    private string $requiredPermission;

    public function __construct(string $requiredPermission)
    {
        $this->requiredPermission = $requiredPermission;
    }

    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        $user = $request->getAttribute('admin_user');
        
        if (!$user || !isset($user['role'])) {
            $response = new SlimResponse();
            $response->getBody()->write(json_encode([
                'error' => 'User role not found',
                'message' => 'Unable to verify permissions'
            ], JSON_UNESCAPED_SLASHES));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

        $role = $user['role'];
        
        // Check permission
        if (!PermissionService::hasPermission($role, $this->requiredPermission)) {
            $response = new SlimResponse();
            $response->getBody()->write(json_encode([
                'error' => 'Insufficient permissions',
                'message' => "You do not have permission to perform this action. Required: {$this->requiredPermission}"
            ], JSON_UNESCAPED_SLASHES));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

        return $handler->handle($request);
    }
}

