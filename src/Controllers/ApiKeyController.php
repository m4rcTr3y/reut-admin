<?php
declare(strict_types=1);

namespace Reut\Admin\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Reut\Admin\Models\ApiKey;

class ApiKeyController
{
    private $config;
    private $apiKeyModel;

    public function __construct()
    {
        $projectRoot = \Reut\Support\ProjectPath::root();
        require $projectRoot . '/config.php';
        $this->config = $config ?? [];
        $this->apiKeyModel = new ApiKey($this->config);
        $this->apiKeyModel->connect();
    }

    public function getApiKeys(Request $request, Response $response): Response
    {
        try {
            // Ensure database connection
            if (!$this->apiKeyModel->pdo) {
                $this->apiKeyModel->connect();
            }
            
            // Check if table exists, if not return empty result
            try {
                $tableCheck = $this->apiKeyModel->sqlQuery(
                    "SELECT COUNT(*) as count FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'api_keys'",
                    []
                );
                
                // Check if sqlQuery returned an error string
                if (is_string($tableCheck)) {
                    // If query failed, try a simpler check
                    try {
                        $simpleCheck = $this->apiKeyModel->sqlQuery("SELECT 1 FROM api_keys LIMIT 1", []);
                        if (is_string($simpleCheck)) {
                            // Table doesn't exist
                            $response->getBody()->write(json_encode([
                                'keys' => [],
                                'total' => 0,
                                'message' => 'api_keys table does not exist. Please run the migration.'
                            ], JSON_UNESCAPED_SLASHES));
                            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
                        }
                    } catch (\Exception $e2) {
                        $response->getBody()->write(json_encode([
                            'keys' => [],
                            'total' => 0,
                            'message' => 'api_keys table does not exist. Please run the migration.'
                        ], JSON_UNESCAPED_SLASHES));
                        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
                    }
                } elseif (empty($tableCheck) || (int)($tableCheck[0]['count'] ?? 0) === 0) {
                    $response->getBody()->write(json_encode([
                        'keys' => [],
                        'total' => 0,
                        'message' => 'api_keys table does not exist. Please run the migration.'
                    ], JSON_UNESCAPED_SLASHES));
                    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
                }
            } catch (\Exception $e) {
                // Table doesn't exist, return empty
                $response->getBody()->write(json_encode([
                    'keys' => [],
                    'total' => 0,
                    'message' => 'api_keys table does not exist. Please run the migration.'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
            }
            
            $queryParams = $request->getQueryParams();
            $page = (int)($queryParams['page'] ?? 1);
            $limit = (int)($queryParams['limit'] ?? 50);
            $offset = ($page - 1) * $limit;

            // Get total count
            $countResult = $this->apiKeyModel->sqlQuery(
                "SELECT COUNT(*) as total FROM api_keys",
                []
            );
            
            // Check if sqlQuery returned an error string
            if (is_string($countResult)) {
                throw new \Exception("Failed to get count: " . $countResult);
            }
            
            $total = (int)($countResult[0]['total'] ?? 0);

            // Get API keys with pagination (don't return secret)
            // Note: LIMIT and OFFSET cannot use named parameters in MySQL, so we use integers directly
            $limitInt = (int)$limit;
            $offsetInt = (int)$offset;
            $keysResult = $this->apiKeyModel->sqlQuery(
                "SELECT id, name, `key`, permissions, allowed_ips, rate_limit, is_active, last_used_at, expires_at, created_at, updated_at FROM api_keys ORDER BY id DESC LIMIT {$limitInt} OFFSET {$offsetInt}",
                []
            );

            // Check if sqlQuery returned an error string
            if (is_string($keysResult)) {
                throw new \Exception("Failed to get API keys: " . $keysResult);
            }

            // Ensure keys is always an array
            $keys = is_array($keysResult) ? $keysResult : [];

            $response->getBody()->write(json_encode([
                'keys' => $keys,
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
                'keys' => [],
                'total' => 0,
                'error' => $e->getMessage()
            ], JSON_UNESCAPED_SLASHES));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }
    }

    public function getApiKey(Request $request, Response $response, array $args): Response
    {
        try {
            $keyId = (int)($args['id'] ?? 0);
            
            if ($keyId === 0) {
                $response->getBody()->write(json_encode([
                    'error' => 'Invalid API key ID'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            $keyResult = $this->apiKeyModel->sqlQuery(
                "SELECT id, name, `key`, permissions, allowed_ips, rate_limit, is_active, last_used_at, expires_at, created_at, updated_at FROM api_keys WHERE id = :id",
                ['id' => $keyId]
            );

            if (empty($keyResult)) {
                $response->getBody()->write(json_encode([
                    'error' => 'API key not found'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            $response->getBody()->write(json_encode([
                'key' => $keyResult[0]
            ], JSON_UNESCAPED_SLASHES));
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'error' => $e->getMessage()
            ], JSON_UNESCAPED_SLASHES));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }
    }

    public function createApiKey(Request $request, Response $response): Response
    {
        try {
            // Try getParsedBody first (if BodyParsingMiddleware has already parsed it)
            $data = $request->getParsedBody();
            if (empty($data) || !is_array($data)) {
                // Fallback to manual JSON decode
                $body = $request->getBody()->getContents();
                $data = json_decode($body, true);
                
                // Handle JSON decode errors
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $response->getBody()->write(json_encode([
                        'success' => false,
                        'error' => 'Invalid JSON: ' . json_last_error_msg()
                    ], JSON_UNESCAPED_SLASHES));
                    return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
                }
            }
            
            if (empty($data) || !is_array($data)) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Request body is required and must be valid JSON'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }
            
            // Get and validate name
            $name = isset($data['name']) ? trim((string)$data['name']) : '';
            $permissions = $data['permissions'] ?? [];
            $allowedIps = $data['allowed_ips'] ?? [];
            $rateLimit = (int)($data['rate_limit'] ?? 1000);
            $expiresAt = $data['expires_at'] ?? null;

            // Validation - check if name is empty or only whitespace
            if (empty($name) || $name === '') {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Name is required and cannot be empty'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            // Generate API key and secret
            $apiKey = 'rk_' . bin2hex(random_bytes(16));
            $secret = bin2hex(random_bytes(32));

            // Hash the secret for storage
            $hashedSecret = password_hash($secret, PASSWORD_DEFAULT);

            // Create API key using direct SQL query (key is a reserved keyword, needs backticks)
            $expiresAtValue = $expiresAt ? date('Y-m-d H:i:s', strtotime($expiresAt)) : null;
            $allowedIpsValue = !empty($allowedIps) ? json_encode($allowedIps) : null;
            
            $result = $this->apiKeyModel->sqlQuery(
                "INSERT INTO api_keys (name, `key`, secret, permissions, allowed_ips, rate_limit, is_active, expires_at, created_at, updated_at) 
                 VALUES (:name, :key, :secret, :permissions, :allowed_ips, :rate_limit, :is_active, :expires_at, NOW(), NOW())",
                [
                    'name' => $name,
                    'key' => $apiKey,
                    'secret' => $hashedSecret,
                    'permissions' => json_encode($permissions),
                    'allowed_ips' => $allowedIpsValue,
                    'rate_limit' => $rateLimit,
                    'is_active' => 1,
                    'expires_at' => $expiresAtValue
                ]
            );
            
            // Check if sqlQuery failed (returns error string on failure)
            if (is_string($result)) {
                throw new \Exception("Failed to create API key: " . $result);
            }
            
            // Verify the key was actually created
            $verify = $this->apiKeyModel->sqlQuery(
                "SELECT id FROM api_keys WHERE `key` = :key",
                ['key' => $apiKey]
            );
            
            if (is_string($verify)) {
                throw new \Exception("Failed to verify API key creation: " . $verify);
            }
            
            if (empty($verify)) {
                throw new \Exception("API key was not created in database");
            }

            // Return the key and secret (only shown once)
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'API key created successfully',
                'api_key' => $apiKey,
                'secret' => $secret,
                'warning' => 'Save the secret now. It will not be shown again.'
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

    public function updateApiKey(Request $request, Response $response, array $args): Response
    {
        try {
            $keyId = (int)($args['id'] ?? 0);
            
            // Try getParsedBody first (if BodyParsingMiddleware has already parsed it)
            $data = $request->getParsedBody();
            if (empty($data) || !is_array($data)) {
                // Fallback to manual JSON decode
                $body = $request->getBody()->getContents();
                $data = json_decode($body, true);
                
                // Handle JSON decode errors
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $response->getBody()->write(json_encode([
                        'success' => false,
                        'error' => 'Invalid JSON: ' . json_last_error_msg()
                    ], JSON_UNESCAPED_SLASHES));
                    return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
                }
            }
            
            if (empty($data) || !is_array($data)) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Request body is required and must be valid JSON'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }
            
            if ($keyId === 0) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Invalid API key ID'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            // Check if key exists
            $existing = $this->apiKeyModel->sqlQuery(
                "SELECT id FROM api_keys WHERE id = :id",
                ['id' => $keyId]
            );

            if (empty($existing)) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'API key not found'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            $updateData = [];
            
            if (isset($data['name'])) {
                $updateData['name'] = $data['name'];
            }

            if (isset($data['permissions'])) {
                $updateData['permissions'] = json_encode($data['permissions']);
            }

            if (isset($data['allowed_ips'])) {
                $updateData['allowed_ips'] = !empty($data['allowed_ips']) ? json_encode($data['allowed_ips']) : null;
            }

            if (isset($data['rate_limit'])) {
                $updateData['rate_limit'] = (int)$data['rate_limit'];
            }

            if (isset($data['is_active'])) {
                $updateData['is_active'] = (int)$data['is_active'];
            }

            if (isset($data['expires_at'])) {
                $updateData['expires_at'] = $data['expires_at'] ? date('Y-m-d H:i:s', strtotime($data['expires_at'])) : null;
            }

            if (empty($updateData)) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'No fields to update'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            // Update API key
            $setClause = [];
            $params = ['id' => $keyId];
            foreach ($updateData as $key => $value) {
                $setClause[] = "{$key} = :{$key}";
                $params[$key] = $value;
            }
            $setClause[] = "updated_at = NOW()";
            
            $this->apiKeyModel->sqlQuery(
                "UPDATE api_keys SET " . implode(', ', $setClause) . " WHERE id = :id",
                $params
            );

            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'API key updated successfully'
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

    public function deleteApiKey(Request $request, Response $response, array $args): Response
    {
        try {
            $keyId = (int)($args['id'] ?? 0);
            
            if ($keyId === 0) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Invalid API key ID'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            // Check if key exists
            $existing = $this->apiKeyModel->sqlQuery(
                "SELECT id FROM api_keys WHERE id = :id",
                ['id' => $keyId]
            );

            if (empty($existing)) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'API key not found'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            // Delete API key
            $this->apiKeyModel->sqlQuery(
                "DELETE FROM api_keys WHERE id = :id",
                ['id' => $keyId]
            );

            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'API key deleted successfully'
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

    public function regenerateSecret(Request $request, Response $response, array $args): Response
    {
        try {
            $keyId = (int)($args['id'] ?? 0);
            
            if ($keyId === 0) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Invalid API key ID'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            // Check if key exists
            $existing = $this->apiKeyModel->sqlQuery(
                "SELECT id FROM api_keys WHERE id = :id",
                ['id' => $keyId]
            );

            if (empty($existing)) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'API key not found'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            // Generate new secret
            $newSecret = bin2hex(random_bytes(32));
            $hashedSecret = password_hash($newSecret, PASSWORD_DEFAULT);

            // Update secret
            $this->apiKeyModel->sqlQuery(
                "UPDATE api_keys SET secret = :secret, updated_at = NOW() WHERE id = :id",
                ['secret' => $hashedSecret, 'id' => $keyId]
            );

            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Secret regenerated successfully',
                'secret' => $newSecret,
                'warning' => 'Save the secret now. It will not be shown again.'
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

