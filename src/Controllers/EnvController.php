<?php
declare(strict_types=1);

namespace Reut\Admin\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Reut\Admin\Services\AuditService;
use Reut\Admin\Services\ErrorSanitizer;
use Reut\Support\ProjectPath;

class EnvController
{
    private $envPath;
    private $backupPath;
    private $config;
    private $auditService;

    public function __construct()
    {
        $projectRoot = ProjectPath::root();
        require $projectRoot . '/config.php';
        $this->config = $config ?? [];
        $this->envPath = $projectRoot . '/.env';
        $this->backupPath = $projectRoot . '/.env.backup';
        $this->auditService = new AuditService($this->config);
    }

    /**
     * Get admin user and IP from request
     */
    private function getAuditInfo(Request $request): array
    {
        $user = $request->getAttribute('admin_user');
        $userId = $user['id'] ?? 0;
        
        $ipAddress = $request->getHeaderLine('X-Forwarded-For');
        if (empty($ipAddress)) {
            $ipAddress = $request->getHeaderLine('X-Real-IP');
        }
        if (empty($ipAddress)) {
            $serverParams = $request->getServerParams();
            $ipAddress = $serverParams['REMOTE_ADDR'] ?? '';
        }
        
        return ['userId' => $userId, 'ipAddress' => $ipAddress];
    }

    /**
     * Get all environment variables (values are masked for security)
     */
    public function getEnv(Request $request, Response $response): Response
    {
        try {
            if (!file_exists($this->envPath)) {
                $response->getBody()->write(json_encode([
                    'variables' => [],
                    'error' => ErrorSanitizer::getGenericMessage('not_found')
                ], JSON_UNESCAPED_SLASHES));
                return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            $envContent = file_get_contents($this->envPath);
            $variables = $this->parseEnvFile($envContent);
            
            // Mask sensitive values (passwords, keys, secrets) - server-side enforcement
            $maskedVariables = array_map(function($var) {
                $isSensitive = $this->isSensitive($var['key']);
                
                if ($isSensitive && !empty($var['value'])) {
                    $var['value'] = str_repeat('*', min(20, strlen($var['value'])));
                    $var['masked'] = true;
                } else {
                    $var['masked'] = false;
                }
                
                return $var;
            }, $variables);

            $response->getBody()->write(json_encode([
                'variables' => $maskedVariables,
                'total' => count($maskedVariables)
            ], JSON_UNESCAPED_SLASHES));
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'error' => ErrorSanitizer::sanitize($e, 'getEnv')
            ], JSON_UNESCAPED_SLASHES));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }
    }

    /**
     * Check if an environment variable key is sensitive
     */
    private function isSensitive(string $key): bool
    {
        $keyLower = strtolower($key);
        $sensitivePatterns = ['password', 'secret', 'key', 'token', 'api_key', 'apikey', 'private', 'credential'];
        
        foreach ($sensitivePatterns as $pattern) {
            if (strpos($keyLower, $pattern) !== false) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Get a single environment variable value (for editing)
     * Restricted for sensitive variables - returns masked value
     */
    public function getEnvVariable(Request $request, Response $response, array $args): Response
    {
        try {
            $key = $args['key'] ?? '';
            if (empty($key)) {
                $response->getBody()->write(json_encode([
                    'error' => 'Variable key is required'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            // Restrict access to sensitive variables
            if ($this->isSensitive($key)) {
                // Audit log sensitive access attempt
                $auditInfo = $this->getAuditInfo($request);
                $this->auditService->logSensitiveAccess(
                    $auditInfo['userId'],
                    'env',
                    $key,
                    ['attempted_access' => true],
                    $auditInfo['ipAddress']
                );
                
                $response->getBody()->write(json_encode([
                    'error' => 'Access to sensitive environment variables is restricted for security reasons',
                    'key' => $key,
                    'value' => str_repeat('*', 20), // Return masked value
                    'masked' => true
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(403)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            if (!file_exists($this->envPath)) {
                $response->getBody()->write(json_encode([
                    'error' => 'Environment file not found'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            $envContent = file_get_contents($this->envPath);
            $variables = $this->parseEnvFile($envContent);
            
            foreach ($variables as $var) {
                if ($var['key'] === $key) {
                    $response->getBody()->write(json_encode([
                        'key' => $var['key'],
                        'value' => $var['value'],
                        'comment' => $var['comment'] ?? ''
                    ], JSON_UNESCAPED_SLASHES));
                    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
                }
            }

            $response->getBody()->write(json_encode([
                'error' => 'Variable not found'
            ], JSON_UNESCAPED_SLASHES));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json; charset=utf-8');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'error' => 'An error occurred while retrieving the variable'
            ], JSON_UNESCAPED_SLASHES));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }
    }

    /**
     * Update or create an environment variable
     */
    public function updateEnvVariable(Request $request, Response $response): Response
    {
        try {
            $data = json_decode($request->getBody()->getContents(), true);
            $key = $data['key'] ?? '';
            $value = $data['value'] ?? '';
            $comment = $data['comment'] ?? '';

            if (empty($key)) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Variable key is required'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            // Validate key name (prevent injection)
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Invalid variable key format'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            // Prevent modification of critical admin variables
            $protectedKeys = ['ADMIN_JWT_SECRET', 'ADMIN_JWT_ALGORITHM'];
            if (in_array($key, $protectedKeys)) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'This variable is protected and cannot be modified'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(403)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            // Create backup before modification
            if (file_exists($this->envPath)) {
                copy($this->envPath, $this->backupPath);
            }

            // Read current env file
            $envContent = file_exists($this->envPath) ? file_get_contents($this->envPath) : '';
            $variables = $this->parseEnvFile($envContent);
            
            // Update or add variable
            $found = false;
            foreach ($variables as &$var) {
                if ($var['key'] === $key) {
                    $var['value'] = $value;
                    if (!empty($comment)) {
                        $var['comment'] = $comment;
                    }
                    $found = true;
                    break;
                }
            }
            
            if (!$found) {
                $variables[] = [
                    'key' => $key,
                    'value' => $value,
                    'comment' => $comment
                ];
            }

            // Write back to file
            $newContent = $this->buildEnvFile($variables);
            if (file_put_contents($this->envPath, $newContent) === false) {
                // Restore backup on failure
                if (file_exists($this->backupPath)) {
                    copy($this->backupPath, $this->envPath);
                }
                throw new \Exception('Failed to write .env file');
            }

            // Audit log
            $auditInfo = $this->getAuditInfo($request);
            $oldValue = null;
            if ($found) {
                // Find old value before update
                $envContent = file_exists($this->envPath) ? file_get_contents($this->backupPath) : '';
                if (file_exists($this->backupPath)) {
                    $oldVariables = $this->parseEnvFile($envContent);
                    foreach ($oldVariables as $var) {
                        if ($var['key'] === $key) {
                            $oldValue = $var['value'] ?? null;
                            break;
                        }
                    }
                }
            }
            $this->auditService->logEnvChange(
                $auditInfo['userId'],
                $found ? 'env_update' : 'env_create',
                $key,
                $oldValue,
                $auditInfo['ipAddress']
            );

            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Environment variable updated successfully'
            ], JSON_UNESCAPED_SLASHES));
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        } catch (\Exception $e) {
            // Restore backup on error
            if (file_exists($this->backupPath) && file_exists($this->envPath)) {
                copy($this->backupPath, $this->envPath);
            }
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ], JSON_UNESCAPED_SLASHES));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }
    }

    /**
     * Delete an environment variable
     */
    public function deleteEnvVariable(Request $request, Response $response, array $args): Response
    {
        try {
            $key = $args['key'] ?? '';
            if (empty($key)) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Variable key is required'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            // Prevent deletion of critical variables
            $protectedKeys = ['ADMIN_JWT_SECRET', 'ADMIN_JWT_ALGORITHM', 'DB_HOST', 'DB_NAME', 'DB_USER'];
            if (in_array($key, $protectedKeys)) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'This variable is protected and cannot be deleted'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(403)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            if (!file_exists($this->envPath)) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => '.env file not found'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            // Create backup
            copy($this->envPath, $this->backupPath);

            // Read and remove variable
            $envContent = file_get_contents($this->envPath);
            $variables = $this->parseEnvFile($envContent);
            
            $variables = array_filter($variables, function($var) use ($key) {
                return $var['key'] !== $key;
            });
            $variables = array_values($variables); // Re-index

            // Write back
            $newContent = $this->buildEnvFile($variables);
            if (file_put_contents($this->envPath, $newContent) === false) {
                // Restore backup on failure
                if (file_exists($this->backupPath)) {
                    copy($this->backupPath, $this->envPath);
                }
                throw new \Exception('Failed to write .env file');
            }

            // Audit log
            $auditInfo = $this->getAuditInfo($request);
            $this->auditService->logEnvChange(
                $auditInfo['userId'],
                'env_delete',
                $key,
                null,
                $auditInfo['ipAddress']
            );

            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Environment variable deleted successfully'
            ], JSON_UNESCAPED_SLASHES));
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        } catch (\Exception $e) {
            // Restore backup on error
            if (file_exists($this->backupPath) && file_exists($this->envPath)) {
                copy($this->backupPath, $this->envPath);
            }
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ], JSON_UNESCAPED_SLASHES));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }
    }

    /**
     * Parse .env file content into array
     * Handles multiline values, escaped quotes, and special characters
     */
    private function parseEnvFile(string $content): array
    {
        $variables = [];
        $lines = explode("\n", $content);
        $currentComment = '';
        $currentKey = null;
        $currentValue = '';
        $inMultiline = false;
        $quoteChar = null;

        foreach ($lines as $lineNum => $line) {
            $originalLine = $line;
            $line = rtrim($line); // Remove trailing whitespace
            
            // Skip empty lines (unless we're in a multiline value)
            if (empty($line) && !$inMultiline) {
                continue;
            }

            // Handle comments (only if not in multiline)
            if (!$inMultiline && strpos($line, '#') === 0) {
                $currentComment = substr($line, 1);
                continue;
            }

            // Handle multiline values
            if ($inMultiline) {
                // Check for closing quote
                if ($quoteChar && substr($line, -1) === $quoteChar && substr($line, -2, 1) !== '\\') {
                    // End of multiline value
                    $currentValue .= "\n" . substr($line, 0, -1); // Remove closing quote
                    $inMultiline = false;
                    $quoteChar = null;
                    
                    // Unescape the value
                    $currentValue = $this->unescapeValue($currentValue, $quoteChar);
                    
                    $variables[] = [
                        'key' => $currentKey,
                        'value' => $currentValue,
                        'comment' => $currentComment
                    ];
                    $currentKey = null;
                    $currentValue = '';
                    $currentComment = '';
                    continue;
                } else {
                    // Continue multiline
                    $currentValue .= "\n" . $line;
                    continue;
                }
            }

            // Parse KEY=VALUE
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                
                // Validate key format
                if (empty($key) || !preg_match('/^[A-Z_][A-Z0-9_]*$/i', $key)) {
                    continue; // Skip invalid keys
                }
                
                $value = trim($value);
                
                // Check for multiline value (starts with quote but doesn't end with unescaped quote)
                if ((strpos($value, '"') === 0 || strpos($value, "'") === 0)) {
                    $quoteChar = $value[0];
                    if (strlen($value) > 1 && substr($value, -1) === $quoteChar && substr($value, -2, 1) !== '\\') {
                        // Single line quoted value
                        $value = substr($value, 1, -1);
                        $value = $this->unescapeValue($value, $quoteChar);
                    } else {
                        // Multiline value starts
                        $currentKey = $key;
                        $currentValue = substr($value, 1); // Remove opening quote
                        $inMultiline = true;
                        continue;
                    }
                } else {
                    // Unquoted value - handle escaped characters
                    $value = $this->unescapeValue($value, null);
                }

                $variables[] = [
                    'key' => $key,
                    'value' => $value,
                    'comment' => $currentComment
                ];
                $currentComment = '';
            }
        }

        // Handle case where multiline wasn't closed properly
        if ($inMultiline && $currentKey) {
            // Treat as unquoted value
            $variables[] = [
                'key' => $currentKey,
                'value' => $currentValue,
                'comment' => $currentComment
            ];
        }

        return $variables;
    }

    /**
     * Unescape value (handle escaped quotes, newlines, etc.)
     */
    private function unescapeValue(string $value, ?string $quoteChar): string
    {
        if ($quoteChar === '"') {
            // Handle double-quoted strings: \n, \t, \", \\
            $value = str_replace(['\\n', '\\t', '\\r'], ["\n", "\t", "\r"], $value);
            $value = str_replace(['\\"', '\\\\'], ['"', '\\'], $value);
        } elseif ($quoteChar === "'") {
            // Single quotes: only escape single quotes
            $value = str_replace("\\'", "'", $value);
            $value = str_replace('\\\\', '\\', $value);
        } else {
            // Unquoted: handle basic escaping
            $value = str_replace(['\\n', '\\t'], ["\n", "\t"], $value);
        }
        
        return $value;
    }

    /**
     * Build .env file content from variables array
     * Properly escapes values with special characters and multiline support
     */
    private function buildEnvFile(array $variables): string
    {
        $content = '';
        foreach ($variables as $var) {
            if (!empty($var['comment'])) {
                $content .= '#' . $var['comment'] . "\n";
            }
            
            $key = $var['key'];
            $value = $var['value'] ?? '';
            
            // Escape the value properly
            $value = $this->escapeValue($value);
            
            $content .= $key . '=' . $value . "\n";
        }
        return $content;
    }

    /**
     * Escape value for .env file format
     */
    private function escapeValue(string $value): string
    {
        // If value contains newlines, tabs, spaces, quotes, or special chars, quote it
        if (preg_match('/[\s\n\t\r"\'#=]/', $value) || empty($value)) {
            // Use double quotes and escape internal quotes and backslashes
            $value = str_replace('\\', '\\\\', $value);
            $value = str_replace('"', '\\"', $value);
            $value = str_replace("\n", '\\n', $value);
            $value = str_replace("\t", '\\t', $value);
            $value = str_replace("\r", '\\r', $value);
            return '"' . $value . '"';
        }
        
        return $value;
    }
}



