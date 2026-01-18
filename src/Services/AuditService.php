<?php
declare(strict_types=1);

namespace Reut\Admin\Services;

use Reut\Admin\Models\AdminAuditLog;

/**
 * Audit Service
 * Centralized audit logging for critical admin actions
 */
class AuditService
{
    private $config;
    private $adminLogModel;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->adminLogModel = new AdminAuditLog($config);
    }

    /**
     * Log an audit event
     * 
     * @param int $userId User ID performing the action
     * @param string $action Action performed (e.g., 'env_update', 'model_delete')
     * @param string $resourceType Type of resource (e.g., 'env', 'model', 'migration')
     * @param string|null $resourceId ID or identifier of the resource
     * @param array $details Additional details about the action
     * @param string|null $ipAddress IP address of the user
     */
    public function log(
        int $userId,
        string $action,
        string $resourceType,
        ?string $resourceId = null,
        array $details = [],
        ?string $ipAddress = null
    ): void {
        try {
            $this->adminLogModel->connect();
            
            $logData = [
                'user_id' => $userId,
                'action' => $action,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'details' => json_encode($details, JSON_UNESCAPED_SLASHES),
                'ip_address' => $ipAddress ?? $this->getClientIp()
            ];

            $this->adminLogModel->addOne($logData);
        } catch (\Exception $e) {
            // Silently fail audit logging to not break the main operation
            // Log to error log instead
            error_log('Audit logging failed: ' . $e->getMessage());
        }
    }

    /**
     * Get client IP address
     */
    private function getClientIp(): string
    {
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($ips[0]);
        }

        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            return trim($_SERVER['HTTP_X_REAL_IP']);
        }

        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    /**
     * Log environment variable change
     */
    public function logEnvChange(int $userId, string $action, string $key, ?string $oldValue = null, ?string $ipAddress = null): void
    {
        $details = [
            'key' => $key,
            'action' => $action
        ];
        
        if ($oldValue !== null) {
            $details['old_value_length'] = strlen($oldValue);
        }
        
        $this->log($userId, $action, 'env', $key, $details, $ipAddress);
    }

    /**
     * Log model operation
     */
    public function logModelOperation(int $userId, string $action, string $modelName, array $details = [], ?string $ipAddress = null): void
    {
        $this->log($userId, $action, 'model', $modelName, $details, $ipAddress);
    }

    /**
     * Log migration operation
     */
    public function logMigrationOperation(int $userId, string $action, ?string $migrationId = null, array $details = [], ?string $ipAddress = null): void
    {
        $this->log($userId, $action, 'migration', $migrationId, $details, $ipAddress);
    }

    /**
     * Log user management operation
     */
    public function logUserOperation(int $userId, string $action, ?int $targetUserId = null, array $details = [], ?string $ipAddress = null): void
    {
        $this->log($userId, $action, 'user', $targetUserId ? (string)$targetUserId : null, $details, $ipAddress);
    }

    /**
     * Log sensitive data access
     */
    public function logSensitiveAccess(int $userId, string $resourceType, string $resourceId, array $details = [], ?string $ipAddress = null): void
    {
        $this->log($userId, 'sensitive_access', $resourceType, $resourceId, $details, $ipAddress);
    }
}

