<?php
declare(strict_types=1);

namespace Reut\Admin\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Reut\Admin\Models\AdminUser;
use Reut\Admin\Services\PermissionService;

class AdminUserController
{
    private $config;
    private $adminUserModel;

    public function __construct()
    {
        $projectRoot = \Reut\Support\ProjectPath::root();
        require $projectRoot . '/config.php';
        $this->config = $config ?? [];
        $this->adminUserModel = new AdminUser($this->config);
        $this->adminUserModel->connect();
    }

    public function getAdminUsers(Request $request, Response $response): Response
    {
        try {
            // Ensure database connection
            if (!$this->adminUserModel->pdo) {
                $this->adminUserModel->connect();
            }
            
            $queryParams = $request->getQueryParams();
            $page = (int)($queryParams['page'] ?? 1);
            $limit = (int)($queryParams['limit'] ?? 50);
            $offset = ($page - 1) * $limit;

            // Get total count
            $countResult = $this->adminUserModel->sqlQuery(
                "SELECT COUNT(*) as total FROM admin_users",
                []
            );
            $total = (int)($countResult[0]['total'] ?? 0);

            // Get admin users with pagination
            // Note: LIMIT and OFFSET cannot use named parameters in MySQL, so we use integers directly
            $limitInt = (int)$limit;
            $offsetInt = (int)$offset;
            $usersResult = $this->adminUserModel->sqlQuery(
                "SELECT id, username, email, role, created_at, updated_at FROM admin_users ORDER BY id DESC LIMIT {$limitInt} OFFSET {$offsetInt}",
                []
            );

            $response->getBody()->write(json_encode([
                'users' => $usersResult ?? [],
                'total' => $total,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'totalPages' => ceil($total / $limit)
                ]
            ], JSON_UNESCAPED_SLASHES));
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'users' => [],
                'total' => 0,
                'error' => $e->getMessage()
            ], JSON_UNESCAPED_SLASHES));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }
    }

    public function getAdminUser(Request $request, Response $response, array $args): Response
    {
        try {
            $userId = (int)($args['id'] ?? 0);
            
            if ($userId === 0) {
                $response->getBody()->write(json_encode([
                    'error' => 'Invalid user ID'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            $userResult = $this->adminUserModel->sqlQuery(
                "SELECT id, username, email, role, created_at, updated_at FROM admin_users WHERE id = :id",
                ['id' => $userId]
            );

            if (empty($userResult)) {
                $response->getBody()->write(json_encode([
                    'error' => 'User not found'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            $response->getBody()->write(json_encode([
                'user' => $userResult[0]
            ], JSON_UNESCAPED_SLASHES));
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'error' => $e->getMessage()
            ], JSON_UNESCAPED_SLASHES));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }
    }

    public function createAdminUser(Request $request, Response $response): Response
    {
        try {
            $data = json_decode($request->getBody()->getContents(), true);
            
            $username = $data['username'] ?? '';
            $email = $data['email'] ?? '';
            $password = $data['password'] ?? '';
            $role = $data['role'] ?? PermissionService::ROLE_ADMIN;

            // Validation
            if (empty($username) || empty($email) || empty($password)) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Username, email, and password are required'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Invalid email format'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            if (strlen($password) < 8) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Password must be at least 8 characters'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            if (!PermissionService::isValidRole($role)) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Invalid role'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            // Check if username or email already exists
            $existing = $this->adminUserModel->sqlQuery(
                "SELECT id FROM admin_users WHERE username = :username OR email = :email",
                ['username' => $username, 'email' => $email]
            );
            
            if (!empty($existing)) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Username or email already exists'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            // Hash password
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            
            // Create admin user
            $this->adminUserModel->addOne([
                'username' => $username,
                'email' => $email,
                'password' => $hashedPassword,
                'role' => $role
            ]);

            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Admin user created successfully'
            ], JSON_UNESCAPED_SLASHES));
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ], JSON_UNESCAPED_SLASHES));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }
    }

    public function updateAdminUser(Request $request, Response $response, array $args): Response
    {
        try {
            $userId = (int)($args['id'] ?? 0);
            $data = json_decode($request->getBody()->getContents(), true);
            
            if ($userId === 0) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Invalid user ID'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            // Check if user exists
            $existing = $this->adminUserModel->sqlQuery(
                "SELECT id FROM admin_users WHERE id = :id",
                ['id' => $userId]
            );

            if (empty($existing)) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'User not found'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            $updateData = [];
            
            if (isset($data['username'])) {
                // Check if username is already taken by another user
                $usernameCheck = $this->adminUserModel->sqlQuery(
                    "SELECT id FROM admin_users WHERE username = :username AND id != :id",
                    ['username' => $data['username'], 'id' => $userId]
                );
                if (!empty($usernameCheck)) {
                    $response->getBody()->write(json_encode([
                        'success' => false,
                        'error' => 'Username already taken'
                    ], JSON_UNESCAPED_SLASHES));
                    return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
                }
                $updateData['username'] = $data['username'];
            }

            if (isset($data['email'])) {
                if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                    $response->getBody()->write(json_encode([
                        'success' => false,
                        'error' => 'Invalid email format'
                    ], JSON_UNESCAPED_SLASHES));
                    return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
                }
                // Check if email is already taken by another user
                $emailCheck = $this->adminUserModel->sqlQuery(
                    "SELECT id FROM admin_users WHERE email = :email AND id != :id",
                    ['email' => $data['email'], 'id' => $userId]
                );
                if (!empty($emailCheck)) {
                    $response->getBody()->write(json_encode([
                        'success' => false,
                        'error' => 'Email already taken'
                    ], JSON_UNESCAPED_SLASHES));
                    return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
                }
                $updateData['email'] = $data['email'];
            }

            if (isset($data['password'])) {
                if (strlen($data['password']) < 8) {
                    $response->getBody()->write(json_encode([
                        'success' => false,
                        'error' => 'Password must be at least 8 characters'
                    ], JSON_UNESCAPED_SLASHES));
                    return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
                }
                $updateData['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
            }

            if (isset($data['role'])) {
                if (!PermissionService::isValidRole($data['role'])) {
                    $response->getBody()->write(json_encode([
                        'success' => false,
                        'error' => 'Invalid role'
                    ], JSON_UNESCAPED_SLASHES));
                    return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
                }
                $updateData['role'] = $data['role'];
            }

            if (empty($updateData)) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'No fields to update'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            // Update user
            $setClause = [];
            $params = ['id' => $userId];
            foreach ($updateData as $key => $value) {
                $setClause[] = "{$key} = :{$key}";
                $params[$key] = $value;
            }
            $setClause[] = "updated_at = NOW()";
            
            $this->adminUserModel->sqlQuery(
                "UPDATE admin_users SET " . implode(', ', $setClause) . " WHERE id = :id",
                $params
            );

            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Admin user updated successfully'
            ], JSON_UNESCAPED_SLASHES));
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ], JSON_UNESCAPED_SLASHES));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }
    }

    public function deleteAdminUser(Request $request, Response $response, array $args): Response
    {
        try {
            $userId = (int)($args['id'] ?? 0);
            
            if ($userId === 0) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Invalid user ID'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            // Prevent deleting yourself
            $currentUser = $request->getAttribute('admin_user');
            if ($currentUser && isset($currentUser['id']) && (int)$currentUser['id'] === $userId) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'You cannot delete your own account'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            // Check if user exists
            $existing = $this->adminUserModel->sqlQuery(
                "SELECT id FROM admin_users WHERE id = :id",
                ['id' => $userId]
            );

            if (empty($existing)) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'User not found'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            // Delete user
            $this->adminUserModel->sqlQuery(
                "DELETE FROM admin_users WHERE id = :id",
                ['id' => $userId]
            );

            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Admin user deleted successfully'
            ], JSON_UNESCAPED_SLASHES));
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ], JSON_UNESCAPED_SLASHES));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }
    }

    public function getRoles(Request $request, Response $response): Response
    {
        try {
            $roles = [
                [
                    'name' => PermissionService::ROLE_SUPER_ADMIN,
                    'label' => 'Super Admin',
                    'permissions' => PermissionService::getPermissions(PermissionService::ROLE_SUPER_ADMIN)
                ],
                [
                    'name' => PermissionService::ROLE_ADMIN,
                    'label' => 'Admin',
                    'permissions' => PermissionService::getPermissions(PermissionService::ROLE_ADMIN)
                ],
                [
                    'name' => PermissionService::ROLE_EDITOR,
                    'label' => 'Editor',
                    'permissions' => PermissionService::getPermissions(PermissionService::ROLE_EDITOR)
                ],
                [
                    'name' => PermissionService::ROLE_VIEWER,
                    'label' => 'Viewer',
                    'permissions' => PermissionService::getPermissions(PermissionService::ROLE_VIEWER)
                ]
            ];

            // Get all available permissions
            $allPermissions = [
                PermissionService::PERMISSION_VIEW_DASHBOARD,
                PermissionService::PERMISSION_VIEW_SCHEMA,
                PermissionService::PERMISSION_VIEW_MODELS,
                PermissionService::PERMISSION_CREATE_MODEL,
                PermissionService::PERMISSION_EDIT_MODEL,
                PermissionService::PERMISSION_DELETE_MODEL,
                PermissionService::PERMISSION_VIEW_MIGRATIONS,
                PermissionService::PERMISSION_RUN_MIGRATIONS,
                PermissionService::PERMISSION_ROLLBACK_MIGRATIONS,
                PermissionService::PERMISSION_VIEW_DATA,
                PermissionService::PERMISSION_CREATE_DATA,
                PermissionService::PERMISSION_EDIT_DATA,
                PermissionService::PERMISSION_DELETE_DATA,
                PermissionService::PERMISSION_EXECUTE_QUERY,
                PermissionService::PERMISSION_VIEW_LOGS,
                PermissionService::PERMISSION_VIEW_ANALYTICS,
                PermissionService::PERMISSION_MANAGE_USERS,
                PermissionService::PERMISSION_MANAGE_ENV,
                PermissionService::PERMISSION_VIEW_DOCS,
            ];

            $response->getBody()->write(json_encode([
                'roles' => $roles,
                'allPermissions' => $allPermissions
            ], JSON_UNESCAPED_SLASHES));
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'roles' => [],
                'allPermissions' => [],
                'error' => $e->getMessage()
            ], JSON_UNESCAPED_SLASHES));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }
    }
}

