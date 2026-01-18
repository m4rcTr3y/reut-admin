<?php
declare(strict_types=1);

namespace Reut\Admin\Services;

use Reut\Admin\Models\AdminLog;
use Reut\DB\DataBase;

class LogService
{
    private $logModel;
    private $config;
    private $retentionDays;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->logModel = new AdminLog($config);
        
        // Get retention period from .env (default: 30 days)
        $this->retentionDays = (int)(getenv('ADMIN_LOG_RETENTION_DAYS') ?: $_ENV['ADMIN_LOG_RETENTION_DAYS'] ?? 30);
    }

    /**
     * Log an admin action
     * 
     * @param string $type Log type: 'request', 'error', 'query', 'migration', 'action'
     * @param string $level Log level: 'info', 'warning', 'error', 'critical'
     * @param string $message Log message
     * @param array $context Additional context data
     * @param int|null $userId Admin user ID
     * @param string|null $ipAddress IP address
     * @param string|null $userAgent User agent
     * @return bool Success status
     */
    public function log(
        string $type,
        string $level,
        string $message,
        array $context = [],
        ?int $userId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): bool {
        try {
            // Sanitize sensitive data from context
            $sanitizedContext = $this->sanitizeContext($context);

            $data = [
                'type' => $type,
                'level' => $level,
                'message' => $message,
                'context' => !empty($sanitizedContext) ? json_encode($sanitizedContext, JSON_UNESCAPED_SLASHES) : null,
                'user_id' => $userId,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent ? substr($userAgent, 0, 500) : null,
            ];

            $this->logModel->connect();
            if (!$this->logModel->pdo) {
                return false;
            }

            return $this->logModel->addOne($data);
        } catch (\Exception $e) {
            // Don't throw - logging failures shouldn't break the app
            error_log("Failed to log admin action: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Auto-clean old logs based on retention period
     */
    public function autoCleanOldLogs(): void
    {
        try {
            $this->logModel->connect();
            if (!$this->logModel->pdo) {
                return;
            }

            // Delete logs older than retention period
            $this->logModel->execute(
                "DELETE FROM admin_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)",
                ['days' => $this->retentionDays]
            );
        } catch (\Exception $e) {
            error_log("Failed to auto-clean old logs: " . $e->getMessage());
        }
    }

    /**
     * Get logs with filtering and pagination
     * Includes both admin logs and project logs
     * 
     * @param string $type Log type filter ('all' for all types)
     * @param string $level Log level filter ('all' for all levels)
     * @param int $limit Number of logs to return
     * @param int $offset Offset for pagination
     * @param string|null $startDate Start date filter (Y-m-d H:i:s)
     * @param string|null $endDate End date filter (Y-m-d H:i:s)
     * @param int|null $userId Filter by user ID
     * @return array Logs and pagination info
     */
    public function getLogs(
        string $type = 'all',
        string $level = 'all',
        int $limit = 100,
        int $offset = 0,
        ?string $startDate = null,
        ?string $endDate = null,
        ?int $userId = null
    ): array {
        try {
            $this->logModel->connect();
            if (!$this->logModel->pdo) {
                return ['logs' => [], 'total' => 0, 'pagination' => []];
            }

            $where = [];
            $params = [];

            if ($type !== 'all') {
                $where[] = "type = :type";
                $params['type'] = $type;
            }

            if ($level !== 'all') {
                $where[] = "level = :level";
                $params['level'] = $level;
            }

            if ($userId !== null) {
                $where[] = "user_id = :user_id";
                $params['user_id'] = $userId;
            }

            if ($startDate !== null) {
                $where[] = "created_at >= :start_date";
                $params['start_date'] = $startDate;
            }

            if ($endDate !== null) {
                $where[] = "created_at <= :end_date";
                $params['end_date'] = $endDate;
            }

            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

            // Get total count
            $countQuery = "SELECT COUNT(*) as total FROM admin_logs {$whereClause}";
            $countResult = $this->logModel->sqlQuery($countQuery, $params);
            $total = $countResult[0]['total'] ?? 0;

            // Get logs
            $query = "SELECT * FROM admin_logs {$whereClause} ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
            $params['limit'] = $limit;
            $params['offset'] = $offset;

            $logs = $this->logModel->sqlQuery($query, $params);

            // Decode JSON context
            foreach ($logs as &$log) {
                if (!empty($log['context'])) {
                    $log['context'] = json_decode($log['context'], true) ?? [];
                } else {
                    $log['context'] = [];
                }
            }

            // Also get project logs from error_log files if they exist
            $projectLogs = $this->getProjectLogs($type, $level, $startDate, $endDate);
            
            // Merge with project logs (limit to avoid too many)
            $allLogs = array_merge($logs ?? [], array_slice($projectLogs, 0, 50));
            usort($allLogs, fn($a, $b) => strtotime($b['created_at'] ?? '1970-01-01') - strtotime($a['created_at'] ?? '1970-01-01'));

            return [
                'logs' => array_slice($allLogs, 0, $limit),
                'total' => (int)$total + count($projectLogs),
                'pagination' => [
                    'limit' => $limit,
                    'offset' => $offset,
                    'totalPages' => ceil(($total + count($projectLogs)) / $limit)
                ]
            ];
        } catch (\Exception $e) {
            error_log("Failed to get logs: " . $e->getMessage());
            return ['logs' => [], 'total' => 0, 'pagination' => []];
        }
    }

    /**
     * Get project logs from error_log files
     */
    private function getProjectLogs(string $type, string $level, ?string $startDate, ?string $endDate): array
    {
        $projectLogs = [];
        $projectRoot = \Reut\Support\ProjectPath::root();
        $logFiles = [
            ini_get('error_log'),
            $projectRoot . '/logs/error.log',
            $projectRoot . '/storage/logs/error.log',
            '/tmp/reut_log',
        ];

        foreach ($logFiles as $logFile) {
            if (empty($logFile) || !file_exists($logFile) || !is_readable($logFile)) {
                continue;
            }

            try {
                $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if (!$lines) {
                    continue;
                }

                // Get last 100 lines
                $lines = array_slice($lines, -100);
                
                foreach ($lines as $line) {
                    // Parse log line (basic parsing)
                    if (preg_match('/\[(.*?)\].*?(ERROR|WARNING|INFO|CRITICAL).*?:(.*)/i', $line, $matches)) {
                        $timestamp = $matches[1] ?? date('Y-m-d H:i:s');
                        $logLevel = strtolower($matches[2] ?? 'info');
                        $message = trim($matches[3] ?? $line);

                        // Apply filters
                        if ($level !== 'all' && $logLevel !== $level) {
                            continue;
                        }

                        // Check date range
                        if ($startDate && strtotime($timestamp) < strtotime($startDate)) {
                            continue;
                        }
                        if ($endDate && strtotime($timestamp) > strtotime($endDate)) {
                            continue;
                        }

                        $projectLogs[] = [
                            'id' => 'file_' . md5($line),
                            'type' => 'project',
                            'level' => $logLevel,
                            'message' => $message,
                            'context' => ['source' => 'file', 'file' => basename($logFile)],
                            'user_id' => null,
                            'ip_address' => null,
                            'user_agent' => null,
                            'created_at' => $timestamp ?: date('Y-m-d H:i:s')
                        ];
                    }
                }
            } catch (\Exception $e) {
                // Skip files that can't be read
                continue;
            }
        }

        return $projectLogs;
    }

    /**
     * Get log statistics
     * 
     * @param string $period Period: '7d', '30d', '90d', 'all'
     * @return array Statistics
     */
    public function getLogStats(string $period = '7d'): array
    {
        try {
            $this->logModel->connect();
            if (!$this->logModel->pdo) {
                return [];
            }

            $dateFilter = '';
            $params = [];

            switch ($period) {
                case '7d':
                    $dateFilter = "WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
                    break;
                case '30d':
                    $dateFilter = "WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
                    break;
                case '90d':
                    $dateFilter = "WHERE created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)";
                    break;
                case 'all':
                default:
                    $dateFilter = '';
                    break;
            }

            // Get counts by type
            $typeStats = $this->logModel->sqlQuery(
                "SELECT type, COUNT(*) as count FROM admin_logs {$dateFilter} GROUP BY type",
                $params
            );

            // Get counts by level
            $levelStats = $this->logModel->sqlQuery(
                "SELECT level, COUNT(*) as count FROM admin_logs {$dateFilter} GROUP BY level",
                $params
            );

            // Get total count
            $totalResult = $this->logModel->sqlQuery(
                "SELECT COUNT(*) as total FROM admin_logs {$dateFilter}",
                $params
            );
            $total = $totalResult[0]['total'] ?? 0;

            return [
                'total' => (int)$total,
                'byType' => $typeStats ?? [],
                'byLevel' => $levelStats ?? [],
                'period' => $period
            ];
        } catch (\Exception $e) {
            error_log("Failed to get log stats: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Clear logs
     * 
     * @param string $type Log type to clear ('all' for all types)
     * @param int|null $olderThanDays Delete logs older than X days (null = delete all)
     * @return int Number of logs deleted
     */
    public function clearLogs(string $type = 'all', ?int $olderThanDays = null): int
    {
        try {
            $this->logModel->connect();
            if (!$this->logModel->pdo) {
                return 0;
            }

            $where = [];
            $params = [];

            if ($type !== 'all') {
                $where[] = "type = :type";
                $params['type'] = $type;
            }

            if ($olderThanDays !== null) {
                $where[] = "created_at < DATE_SUB(NOW(), INTERVAL :days DAY)";
                $params['days'] = $olderThanDays;
            }

            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

            // Get count before deletion
            $countQuery = "SELECT COUNT(*) as total FROM admin_logs {$whereClause}";
            $countResult = $this->logModel->sqlQuery($countQuery, $params);
            $deleted = $countResult[0]['total'] ?? 0;

            // Delete logs
            $query = "DELETE FROM admin_logs {$whereClause}";
            $this->logModel->execute($query, $params);

            return (int)$deleted;
        } catch (\Exception $e) {
            error_log("Failed to clear logs: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Sanitize context data to remove sensitive information
     * 
     * @param array $context Original context
     * @return array Sanitized context
     */
    private function sanitizeContext(array $context): array
    {
        $sensitiveKeys = ['password', 'token', 'secret', 'api_key', 'access_token', 'refresh_token', 'csrf_token'];
        
        foreach ($context as $key => $value) {
            // Convert key to string to handle numeric array keys
            $keyString = (string)$key;
            $lowerKey = strtolower($keyString);
            foreach ($sensitiveKeys as $sensitive) {
                if (strpos($lowerKey, $sensitive) !== false) {
                    $context[$key] = '[REDACTED]';
                    break;
                }
            }
            
            // Recursively sanitize nested arrays
            if (is_array($value)) {
                $context[$key] = $this->sanitizeContext($value);
            }
        }
        
        return $context;
    }
}

