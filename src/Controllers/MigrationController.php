<?php
declare(strict_types=1);

namespace Reut\Admin\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Reut\Admin\Services\AuditService;
use Reut\Admin\Services\MigrationService;
use Reut\Support\ProjectPath;

class MigrationController
{
    private $migrationService;
    private $config;
    private $auditService;

    public function __construct()
    {
        $projectRoot = ProjectPath::root();
        require $projectRoot . '/config.php';
        $this->config = $config ?? [];
        $this->migrationService = new MigrationService($this->config);
        $this->auditService = new AuditService($this->config);
    }

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

    public function getStatus(Request $request, Response $response): Response
    {
        try {
            $status = $this->migrationService->getStatus();
            $response->getBody()->write(json_encode($status));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'error' => $e->getMessage()
            ]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    public function apply(Request $request, Response $response): Response
    {
        $data = json_decode($request->getBody()->getContents(), true);
        $dryRun = $data['dryRun'] ?? false;

        try {
            $result = $this->migrationService->apply($dryRun);
            
            // Audit log
            $auditInfo = $this->getAuditInfo($request);
            $this->auditService->logMigrationOperation(
                $auditInfo['userId'],
                'migration_apply',
                null,
                ['dry_run' => $dryRun, 'result' => $result],
                $auditInfo['ipAddress']
            );
            
            $response->getBody()->write(json_encode($result));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    public function rollback(Request $request, Response $response): Response
    {
        $data = json_decode($request->getBody()->getContents(), true);
        $batch = $data['batch'] ?? null;
        $migration = $data['migration'] ?? null;
        $dryRun = $data['dryRun'] ?? false;

        try {
            $result = $this->migrationService->rollback($batch, $migration, $dryRun);
            
            // Audit log
            $auditInfo = $this->getAuditInfo($request);
            $this->auditService->logMigrationOperation(
                $auditInfo['userId'],
                'migration_rollback',
                $migration,
                ['batch' => $batch, 'dry_run' => $dryRun, 'result' => $result],
                $auditInfo['ipAddress']
            );
            
            $response->getBody()->write(json_encode($result));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    public function getHistory(Request $request, Response $response): Response
    {
        try {
            $history = $this->migrationService->getHistory();
            $response->getBody()->write(json_encode(['history' => $history]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'error' => $e->getMessage()
            ]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    public function deleteMigration(Request $request, Response $response, array $args): Response
    {
        $route = $request->getAttribute('route');
        $migrationId = $args['id'] ?? ($route ? (int)$route->getArgument('id') : null);

        if ($migrationId === null) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Migration ID is required'
            ], JSON_UNESCAPED_SLASHES));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        try {
            $result = $this->migrationService->deleteMigration($migrationId);
            $response->getBody()->write(json_encode($result, JSON_UNESCAPED_SLASHES));
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ], JSON_UNESCAPED_SLASHES));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }
    }

    public function export(Request $request, Response $response): Response
    {
        // Export migrations to JSON/SQL
        $response->getBody()->write(json_encode(['message' => 'Export functionality not yet implemented']));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function import(Request $request, Response $response): Response
    {
        // Import migrations from JSON/SQL
        $response->getBody()->write(json_encode(['message' => 'Import functionality not yet implemented']));
        return $response->withHeader('Content-Type', 'application/json');
    }
}

