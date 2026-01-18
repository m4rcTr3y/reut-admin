<?php
declare(strict_types=1);

namespace Reut\Admin\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Reut\Admin\Models\AdminLog;
use Reut\DB\DataBase;
use Reut\DB\Exceptions\DatabaseConnectionException;
use Reut\Support\ProjectPath;

class AnalyticsController
{
    private $db;
    private $logModel;
    private $config;

    public function __construct()
    {
        $projectRoot = ProjectPath::root();
        require $projectRoot . '/config.php';
        $this->config = $config ?? [];
        $this->db = new DataBase($this->config);
        $this->logModel = new AdminLog($this->config);
        try {
            $this->db->connect();
            $this->logModel->connect();
        } catch (DatabaseConnectionException $e) {
            // Will handle in methods
        }
    }

    public function getAnalytics(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $period = $queryParams['period'] ?? '7d'; // 7d, 30d, 90d, all

        try {
            // Get table statistics
            $tables = $this->db->getTablesList();
            $tableStats = [];
            
            foreach ($tables as $table) {
                try {
                    $countResult = $this->db->sqlQuery("SELECT COUNT(*) as count FROM `{$table}`");
                    $count = $countResult[0]['count'] ?? 0;
                    $tableStats[] = [
                        'name' => $table,
                        'count' => (int)$count
                    ];
                } catch (\Exception $e) {
                    // Skip tables that can't be queried
                    continue;
                }
            }

            // Get date filter for period
            $dateFilter = $this->getDateFilter($period);
            
            // Get API usage from admin_logs
            $apiUsage = $this->getApiUsage($dateFilter);
            
            // Get endpoint popularity
            $endpointPopularity = $this->getEndpointPopularity($dateFilter);
            
            // Get error rates
            $errorRates = $this->getErrorRates($dateFilter);
            
            // Get recent activity
            $recentActivity = $this->getRecentActivity(10);

            $analytics = [
                'tables' => $tableStats,
                'totalRecords' => array_sum(array_column($tableStats, 'count')),
                'totalTables' => count($tableStats),
                'apiUsage' => $apiUsage,
                'endpointPopularity' => $endpointPopularity,
                'errorRates' => $errorRates,
                'recentActivity' => $recentActivity,
                'period' => $period
            ];

            $response->getBody()->write(json_encode($analytics, JSON_UNESCAPED_SLASHES));
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'error' => $e->getMessage()
            ], JSON_UNESCAPED_SLASHES));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }
    }

    private function getDateFilter(string $period): string
    {
        switch ($period) {
            case '7d':
                return "WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
            case '30d':
                return "WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
            case '90d':
                return "WHERE created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)";
            default:
                return '';
        }
    }

    private function getApiUsage(string $dateFilter): array
    {
        try {
            if (!$this->logModel->pdo || !$this->logModel->tableExists('admin_logs')) {
                return [
                    'totalRequests' => 0,
                    'successfulRequests' => 0,
                    'failedRequests' => 0,
                    'averageResponseTime' => 0
                ];
            }

            // Get total requests
            $totalResult = $this->logModel->sqlQuery(
                "SELECT COUNT(*) as total FROM admin_logs {$dateFilter} WHERE type = 'request'",
                []
            );
            $totalRequests = (is_array($totalResult) && isset($totalResult[0])) ? (int)($totalResult[0]['total'] ?? 0) : 0;

            // Get successful requests (status 200-299)
            $successResult = $this->logModel->sqlQuery(
                "SELECT COUNT(*) as total FROM admin_logs {$dateFilter} WHERE type = 'request' AND JSON_EXTRACT(context, '$.status') BETWEEN 200 AND 299",
                []
            );
            $successfulRequests = (is_array($successResult) && isset($successResult[0])) ? (int)($successResult[0]['total'] ?? 0) : 0;

            // Get failed requests (status >= 400)
            $failedResult = $this->logModel->sqlQuery(
                "SELECT COUNT(*) as total FROM admin_logs {$dateFilter} WHERE type = 'request' AND JSON_EXTRACT(context, '$.status') >= 400",
                []
            );
            $failedRequests = (is_array($failedResult) && isset($failedResult[0])) ? (int)($failedResult[0]['total'] ?? 0) : 0;

            // Get average response time
            $avgTimeResult = $this->logModel->sqlQuery(
                "SELECT AVG(JSON_EXTRACT(context, '$.executionTime')) as avg_time FROM admin_logs {$dateFilter} WHERE type = 'request' AND JSON_EXTRACT(context, '$.executionTime') IS NOT NULL",
                []
            );
            $averageResponseTime = (is_array($avgTimeResult) && isset($avgTimeResult[0])) ? round((float)($avgTimeResult[0]['avg_time'] ?? 0), 2) : 0;

            return [
                'totalRequests' => $totalRequests,
                'successfulRequests' => $successfulRequests,
                'failedRequests' => $failedRequests,
                'averageResponseTime' => $averageResponseTime
            ];
        } catch (\Exception $e) {
            return [
                'totalRequests' => 0,
                'successfulRequests' => 0,
                'failedRequests' => 0,
                'averageResponseTime' => 0
            ];
        }
    }

    private function getEndpointPopularity(string $dateFilter): array
    {
        try {
            if (!$this->logModel->pdo || !$this->logModel->tableExists('admin_logs')) {
                return [];
            }

            // Get endpoint popularity from logs
            $result = $this->logModel->sqlQuery(
                "SELECT JSON_EXTRACT(context, '$.path') as path, COUNT(*) as count 
                 FROM admin_logs {$dateFilter} 
                 WHERE type = 'request' AND JSON_EXTRACT(context, '$.path') IS NOT NULL
                 GROUP BY path 
                 ORDER BY count DESC 
                 LIMIT 10",
                []
            );

            if (!is_array($result)) {
                return [];
            }

            return array_map(function($row) {
                if (!is_array($row)) {
                    return null;
                }
                return [
                    'path' => trim($row['path'] ?? '', '"'),
                    'count' => (int)($row['count'] ?? 0)
                ];
            }, array_filter($result, 'is_array'));
        } catch (\Exception $e) {
            return [];
        }
    }

    private function getErrorRates(string $dateFilter): array
    {
        try {
            if (!$this->logModel->pdo || !$this->logModel->tableExists('admin_logs')) {
                return [];
            }

            // Get error rates by level
            $result = $this->logModel->sqlQuery(
                "SELECT level, COUNT(*) as count 
                 FROM admin_logs {$dateFilter} 
                 WHERE level IN ('error', 'critical', 'warning')
                 GROUP BY level",
                []
            );

            // Ensure result is an array (sqlQuery can return false on error)
            if (!is_array($result) || $result === false) {
                return [];
            }

            // Ensure all elements are arrays and have required keys
            $filtered = array_filter($result, function($row) {
                return is_array($row) && isset($row['level']) && isset($row['count']);
            });
            
            return array_values($filtered); // Re-index array
        } catch (\Exception $e) {
            return [];
        }
    }

    private function getRecentActivity(int $limit): array
    {
        try {
            if (!$this->logModel->pdo || !$this->logModel->tableExists('admin_logs')) {
                return [];
            }

            $result = $this->logModel->sqlQuery(
                "SELECT id, type, level, message, created_at, context 
                 FROM admin_logs 
                 ORDER BY created_at DESC 
                 LIMIT :limit",
                ['limit' => $limit]
            );

            // Ensure result is an array (sqlQuery can return false on error)
            if (!is_array($result) || $result === false) {
                return [];
            }

            // Decode context JSON
            foreach ($result as &$log) {
                if (is_array($log) && !empty($log['context'])) {
                    $log['context'] = json_decode($log['context'], true) ?? [];
                }
            }

            // Filter out any non-array elements
            return array_values(array_filter($result, 'is_array'));
        } catch (\Exception $e) {
            return [];
        }
    }
}

