<?php
declare(strict_types=1);

namespace Reut\Admin\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Reut\Admin\Services\LogService;
use Reut\Support\ProjectPath;

class LogsController
{
    private $logService;
    private $config;

    public function __construct()
    {
        $projectRoot = ProjectPath::root();
        require $projectRoot . '/config.php';
        $this->config = $config ?? [];
        $this->logService = new LogService($this->config);
    }

    public function getLogs(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $type = $queryParams['type'] ?? 'all'; // all, request, error, query, migration, action
        $level = $queryParams['level'] ?? 'all'; // all, info, warning, error, critical
        $limit = (int)($queryParams['limit'] ?? 100);
        $offset = (int)($queryParams['offset'] ?? 0);
        $startDate = $queryParams['startDate'] ?? null;
        $endDate = $queryParams['endDate'] ?? null;
        $userId = isset($queryParams['userId']) ? (int)$queryParams['userId'] : null;

        try {
            $result = $this->logService->getLogs(
                $type,
                $level,
                $limit,
                $offset,
                $startDate,
                $endDate,
                $userId
            );

            $response->getBody()->write(json_encode($result, JSON_UNESCAPED_SLASHES));
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'logs' => [],
                'total' => 0,
                'error' => $e->getMessage()
            ], JSON_UNESCAPED_SLASHES));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }
    }

    public function getStats(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $period = $queryParams['period'] ?? '7d'; // 7d, 30d, 90d, all

        try {
            $stats = $this->logService->getLogStats($period);
            $response->getBody()->write(json_encode($stats, JSON_UNESCAPED_SLASHES));
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'error' => $e->getMessage()
            ], JSON_UNESCAPED_SLASHES));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }
    }

    public function clearLogs(Request $request, Response $response): Response
    {
        $data = json_decode($request->getBody()->getContents(), true);
        $type = $data['type'] ?? 'all';
        $olderThanDays = isset($data['olderThanDays']) ? (int)$data['olderThanDays'] : null;

        try {
            $deleted = $this->logService->clearLogs($type, $olderThanDays);
            $response->getBody()->write(json_encode([
                'success' => true,
                'deleted' => $deleted,
                'message' => "Deleted {$deleted} log entries"
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

    public function exportLogs(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $type = $queryParams['type'] ?? 'all';
        $level = $queryParams['level'] ?? 'all';
        $format = $queryParams['format'] ?? 'json'; // json, csv

        try {
            // Get all logs (no limit for export)
            $result = $this->logService->getLogs($type, $level, 10000, 0);
            $logs = $result['logs'] ?? [];

            if ($format === 'csv') {
                // Generate CSV
                $csv = "ID,Type,Level,Message,User ID,IP Address,Created At\n";
                foreach ($logs as $log) {
                    $csv .= sprintf(
                        "%d,%s,%s,\"%s\",%s,%s,%s\n",
                        $log['id'],
                        $log['type'],
                        $log['level'],
                        str_replace('"', '""', $log['message']),
                        $log['user_id'] ?? '',
                        $log['ip_address'] ?? '',
                        $log['created_at']
                    );
                }

                $response->getBody()->write($csv);
                return $response
                    ->withHeader('Content-Type', 'text/csv; charset=utf-8')
                    ->withHeader('Content-Disposition', 'attachment; filename="admin_logs_' . date('Y-m-d') . '.csv"');
            } else {
                // JSON export
                $response->getBody()->write(json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                return $response
                    ->withHeader('Content-Type', 'application/json; charset=utf-8')
                    ->withHeader('Content-Disposition', 'attachment; filename="admin_logs_' . date('Y-m-d') . '.json"');
            }
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'error' => $e->getMessage()
            ], JSON_UNESCAPED_SLASHES));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }
    }
}
