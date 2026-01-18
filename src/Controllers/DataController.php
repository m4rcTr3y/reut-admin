<?php
declare(strict_types=1);

namespace Reut\Admin\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Reut\Admin\Services\DataService;
use Reut\Admin\Services\ErrorSanitizer;
use Reut\Support\ProjectPath;

class DataController
{
    private $dataService;
    private $config;

    public function __construct()
    {
        $projectRoot = ProjectPath::root();
        require $projectRoot . '/config.php';
        $this->config = $config ?? [];
        $this->dataService = new DataService($this->config);
    }

    /**
     * Sanitize SQL identifier (table/column name) to prevent SQL injection
     */
    private function sanitizeIdentifier(string $identifier): string
    {
        $identifier = trim($identifier);
        if (empty($identifier)) {
            throw new \InvalidArgumentException('Identifier cannot be empty');
        }
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier)) {
            throw new \InvalidArgumentException('Invalid identifier format');
        }
        return $identifier;
    }

    /**
     * Validate table name
     */
    private function validateTable(string $table): string
    {
        if (empty($table)) {
            throw new \InvalidArgumentException('Table name is required');
        }
        return $this->sanitizeIdentifier($table);
    }

    /**
     * Validate record ID
     */
    private function validateId($id): string
    {
        if (empty($id)) {
            throw new \InvalidArgumentException('Record ID is required');
        }
        // Allow alphanumeric and common ID formats
        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', (string)$id)) {
            throw new \InvalidArgumentException('Invalid record ID format');
        }
        return (string)$id;
    }

    public function getData(Request $request, Response $response, array $args): Response
    {
        try {
            $route = $request->getAttribute('route');
            $table = $args['table'] ?? ($route ? $route->getArgument('table') : '');
            $table = $this->validateTable($table);
            
            $queryParams = $request->getQueryParams();
            $page = max(1, (int)($queryParams['page'] ?? 1));
            $perPage = min(100, max(1, (int)($queryParams['perPage'] ?? 50))); // Limit to 100 max
            $filters = $queryParams['filters'] ?? [];
            
            if (is_string($filters)) {
                $filters = json_decode($filters, true) ?? [];
            }
            
            // Validate filters structure
            if (!is_array($filters)) {
                $filters = [];
            }

            $result = $this->dataService->getTableData($table, $page, $perPage, $filters);
            $response->getBody()->write(json_encode($result));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\InvalidArgumentException $e) {
            $response->getBody()->write(json_encode([
                'error' => ErrorSanitizer::sanitize($e, 'getData')
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'error' => ErrorSanitizer::sanitize($e, 'getData')
            ]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    public function getRecord(Request $request, Response $response, array $args): Response
    {
        try {
            $route = $request->getAttribute('route');
            $table = $args['table'] ?? ($route ? $route->getArgument('table') : '');
            $table = $this->validateTable($table);
            $id = $args['id'] ?? ($route ? $route->getArgument('id') : '');
            $id = $this->validateId($id);

            $record = $this->dataService->getRecord($table, $id);
            if (!$record) {
                $response->getBody()->write(json_encode(['error' => ErrorSanitizer::getGenericMessage('not_found')]));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
            }
            $response->getBody()->write(json_encode(['data' => $record]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\InvalidArgumentException $e) {
            $response->getBody()->write(json_encode([
                'error' => ErrorSanitizer::sanitize($e, 'getRecord')
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'error' => ErrorSanitizer::sanitize($e, 'getRecord')
            ]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    public function createRecord(Request $request, Response $response, array $args): Response
    {
        try {
            $route = $request->getAttribute('route');
            $table = $args['table'] ?? ($route ? $route->getArgument('table') : '');
            $table = $this->validateTable($table);
            $data = json_decode($request->getBody()->getContents(), true);

            if (!is_array($data)) {
                throw new \InvalidArgumentException('Invalid data format');
            }

            $record = $this->dataService->createRecord($table, $data);
            $response->getBody()->write(json_encode([
                'success' => true,
                'data' => $record
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\InvalidArgumentException $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => ErrorSanitizer::sanitize($e, 'createRecord')
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => ErrorSanitizer::sanitize($e, 'createRecord')
            ]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    public function updateRecord(Request $request, Response $response, array $args): Response
    {
        try {
            $route = $request->getAttribute('route');
            $table = $args['table'] ?? ($route ? $route->getArgument('table') : '');
            $table = $this->validateTable($table);
            $id = $args['id'] ?? ($route ? $route->getArgument('id') : '');
            $id = $this->validateId($id);
            $data = json_decode($request->getBody()->getContents(), true);

            if (!is_array($data)) {
                throw new \InvalidArgumentException('Invalid data format');
            }

            $record = $this->dataService->updateRecord($table, $id, $data);
            if (!$record) {
                $response->getBody()->write(json_encode(['error' => ErrorSanitizer::getGenericMessage('not_found')]));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
            }
            $response->getBody()->write(json_encode([
                'success' => true,
                'data' => $record
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\InvalidArgumentException $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => ErrorSanitizer::sanitize($e, 'updateRecord')
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => ErrorSanitizer::sanitize($e, 'updateRecord')
            ]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    public function deleteRecord(Request $request, Response $response, array $args): Response
    {
        try {
            $route = $request->getAttribute('route');
            $table = $args['table'] ?? ($route ? $route->getArgument('table') : '');
            $table = $this->validateTable($table);
            $id = $args['id'] ?? ($route ? $route->getArgument('id') : '');
            $id = $this->validateId($id);

            $this->dataService->deleteRecord($table, $id);
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Record deleted successfully'
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\InvalidArgumentException $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => ErrorSanitizer::sanitize($e, 'deleteRecord')
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => ErrorSanitizer::sanitize($e, 'deleteRecord')
            ]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }
}

