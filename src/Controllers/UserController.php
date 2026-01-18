<?php
declare(strict_types=1);

namespace Reut\Admin\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Reut\Support\ProjectPath;

class UserController
{
    private $config;

    public function __construct()
    {
        $projectRoot = ProjectPath::root();
        require $projectRoot . '/config.php';
        $this->config = $config ?? [];
    }

    public function getUsers(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $page = (int)($queryParams['page'] ?? 1);
        $limit = (int)($queryParams['limit'] ?? 50);
        $offset = ($page - 1) * $limit;

        // Check if auth is enabled
        $authEnabled = $_ENV['REUT_AUTH_ENABLED'] ?? getenv('REUT_AUTH_ENABLED') ?? 'true';
        $authEnabled = strtolower($authEnabled) === 'true';
        
        if (!$authEnabled) {
            $response->getBody()->write(json_encode([
                'users' => [],
                'total' => 0,
                'message' => 'Authentication is not enabled'
            ], JSON_UNESCAPED_SLASHES));
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        // Try to get auth config
        $projectRoot = ProjectPath::root();
        $authConfigPath = $projectRoot . '/auth.php';
        $authTable = 'users'; // default
        
        if (file_exists($authConfigPath)) {
            $authConfig = require $authConfigPath;
            $authTable = $authConfig['table'] ?? 'users';
        }

        // Get auth model name
        $authModelName = ucfirst(rtrim($authTable, 's')); // 'users' -> 'User'
        $authModelClass = 'Reut\\Models\\' . $authModelName . 'Table';

        try {
            if (!class_exists($authModelClass)) {
                // Try alternative naming
                $authModelClass = 'Reut\\Models\\' . ucfirst($authTable) . 'Table';
                if (!class_exists($authModelClass)) {
                    $response->getBody()->write(json_encode([
                        'users' => [],
                        'total' => 0,
                        'message' => 'Auth model not found. Looking for: ' . $authModelClass
                    ], JSON_UNESCAPED_SLASHES));
                    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
                }
            }

            $authModel = new $authModelClass($this->config);
            $authModel->connect();
            
            if (!$authModel->pdo) {
                throw new \Exception('Database connection failed');
            }

            // Get total count
            $countResult = $authModel->sqlQuery("SELECT COUNT(*) as total FROM `{$authTable}`", []);
            $total = (int)($countResult[0]['total'] ?? 0);

            // Get users with pagination
            $usersResult = $authModel->sqlQuery(
                "SELECT * FROM `{$authTable}` ORDER BY id DESC LIMIT :limit OFFSET :offset",
                ['limit' => $limit, 'offset' => $offset]
            );

            // Remove password from response
            $users = array_map(function($user) {
                unset($user['password']);
                return $user;
            }, $usersResult ?? []);

            // Also get current admin user
            $currentUser = null;
            try {
                $authHeader = $request->getHeaderLine('Authorization');
                if ($authHeader && preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
                    $token = $matches[1];
                    $adminAuth = new \Reut\Admin\AdminAuth($this->config);
                    $userData = $adminAuth->validateToken($token);
                    
                    if ($userData && isset($userData['id'])) {
                        $adminUserModel = new \Reut\Admin\Models\AdminUser($this->config);
                        $adminUserModel->connect();
                        $adminUserResult = $adminUserModel->sqlQuery(
                            "SELECT id, username, email, role, created_at FROM admin_users WHERE id = :id",
                            ['id' => $userData['id']]
                        );
                        if (!empty($adminUserResult)) {
                            $currentUser = $adminUserResult[0];
                            $currentUser['isCurrentUser'] = true;
                        }
                    }
                }
            } catch (\Exception $e) {
                // Ignore errors getting current user
            }

            $response->getBody()->write(json_encode([
                'users' => $users,
                'currentUser' => $currentUser,
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

    public function createUser(Request $request, Response $response): Response
    {
        $data = json_decode($request->getBody()->getContents(), true);
        
        // Check if auth is enabled
        $authEnabled = $_ENV['REUT_AUTH_ENABLED'] ?? getenv('REUT_AUTH_ENABLED') ?? 'true';
        $authEnabled = strtolower($authEnabled) === 'true';
        
        if (!$authEnabled) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Authentication is not enabled'
            ], JSON_UNESCAPED_SLASHES));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        // Validate required fields
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';
        
        if (empty($email) || empty($password)) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Email and password are required'
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

        // Get auth config
        $projectRoot = ProjectPath::root();
        $authConfigPath = $projectRoot . '/auth.php';
        $authTable = 'users';
        
        if (file_exists($authConfigPath)) {
            $authConfig = require $authConfigPath;
            $authTable = $authConfig['table'] ?? 'users';
        }

        // Get auth model
        $authModelName = ucfirst(rtrim($authTable, 's'));
        $authModelClass = 'Reut\\Models\\' . $authModelName . 'Table';

        try {
            if (!class_exists($authModelClass)) {
                $authModelClass = 'Reut\\Models\\' . ucfirst($authTable) . 'Table';
                if (!class_exists($authModelClass)) {
                    throw new \Exception('Auth model not found');
                }
            }

            $authModel = new $authModelClass($this->config);
            $authModel->connect();
            
            if (!$authModel->pdo) {
                throw new \Exception('Database connection failed');
            }

            // Check if email already exists
            $existing = $authModel->sqlQuery(
                "SELECT id FROM `{$authTable}` WHERE email = :email",
                ['email' => $email]
            );
            
            if (!empty($existing)) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Email already exists'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            // Hash password
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            
            // Create user
            $userData = [
                'email' => $email,
                'password' => $hashedPassword,
            ];
            
            // Add optional fields
            if (isset($data['name'])) {
                $userData['name'] = $data['name'];
            }
            if (isset($data['username'])) {
                $userData['username'] = $data['username'];
            }

            $authModel->addOne($userData);

            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'User created successfully'
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
}
