<?php
declare(strict_types=1);

namespace Reut\Admin\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Reut\Admin\Services\ErrorSanitizer;
use Reut\Support\ProjectPath;
use PDO;
use PDOException;

class QueryController
{
    private $pdo;
    private $config;
    private $validTables = null;
    private $maxQueryLength = 10000; // Maximum query length
    private $maxResultRows = 1000; // Maximum result rows
    private $maxExecutionTime = 30; // Maximum execution time in seconds
    private $maxComplexityScore = 50; // Maximum query complexity score

    public function __construct()
    {
        $projectRoot = ProjectPath::root();
        require $projectRoot . '/config.php';
        $this->config = $config ?? [];
        $this->connect();
    }

    /**
     * Sanitize SQL identifier (table/column name) to prevent SQL injection
     */
    private function sanitizeIdentifier(string $identifier): string
    {
        $identifier = trim($identifier);
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier)) {
            throw new \InvalidArgumentException("Invalid SQL identifier: '{$identifier}'");
        }
        return $identifier;
    }

    /**
     * Get list of valid table names from database
     */
    private function getValidTables(): array
    {
        if ($this->validTables === null) {
            try {
                $dbname = $this->config['dbname'] ?? '';
                $stmt = $this->pdo->prepare(
                    "SELECT table_name FROM information_schema.tables WHERE table_schema = :dbname AND table_type = 'BASE TABLE'"
                );
                $stmt->execute(['dbname' => $dbname]);
                $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $this->validTables = array_column($result, 'table_name');
            } catch (\Exception $e) {
                $this->validTables = [];
            }
        }
        return $this->validTables;
    }

    /**
     * Calculate query complexity score
     * Higher score = more complex query (more resource intensive)
     */
    private function calculateComplexity(string $query): int
    {
        $score = 0;
        $queryUpper = strtoupper($query);
        
        // Base score from query length
        $score += (int)(strlen($query) / 100);
        
        // JOIN operations (expensive)
        $score += substr_count($queryUpper, ' JOIN ') * 5;
        $score += substr_count($queryUpper, ' INNER JOIN ') * 5;
        $score += substr_count($queryUpper, ' LEFT JOIN ') * 5;
        $score += substr_count($queryUpper, ' RIGHT JOIN ') * 5;
        $score += substr_count($queryUpper, ' FULL JOIN ') * 5;
        
        // Subqueries (very expensive)
        $score += substr_count($queryUpper, ' SELECT ') * 10;
        $score += substr_count($queryUpper, ' (SELECT ') * 10;
        
        // GROUP BY and aggregations
        $score += substr_count($queryUpper, ' GROUP BY ') * 3;
        $score += substr_count($queryUpper, ' ORDER BY ') * 2;
        $score += substr_count($queryUpper, ' HAVING ') * 3;
        $score += substr_count($queryUpper, ' COUNT(') * 2;
        $score += substr_count($queryUpper, ' SUM(') * 2;
        $score += substr_count($queryUpper, ' AVG(') * 2;
        $score += substr_count($queryUpper, ' MAX(') * 2;
        $score += substr_count($queryUpper, ' MIN(') * 2;
        
        // WHERE clauses with multiple conditions
        $score += substr_count($queryUpper, ' AND ') * 1;
        $score += substr_count($queryUpper, ' OR ') * 1;
        $score += substr_count($queryUpper, ' LIKE ') * 2;
        $score += substr_count($queryUpper, ' IN (') * 2;
        
        // DISTINCT (requires sorting)
        $score += substr_count($queryUpper, ' DISTINCT ') * 3;
        
        // UNION (combines multiple queries)
        $score += substr_count($queryUpper, ' UNION ') * 8;
        
        return $score;
    }

    /**
     * Validate query for dangerous patterns
     */
    private function validateQuery(string $query): void
    {
        // Block dangerous SQL patterns
        $dangerousPatterns = [
            '/\b(UNION|DROP|DELETE|TRUNCATE|ALTER|CREATE|INSERT|UPDATE|EXEC|EXECUTE|CALL|PROCEDURE|FUNCTION)\b/i',
            '/;\s*(DROP|DELETE|TRUNCATE|ALTER|CREATE|INSERT|UPDATE)/i',
            '/\/\*.*\*\//s', // SQL comments
            '/--.*$/m', // SQL comments
            '/`.*`.*`.*`/i', // Multiple backticks (potential injection)
        ];

        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $query)) {
                throw new \InvalidArgumentException('Query contains potentially dangerous SQL patterns');
            }
        }

        // Check query length
        if (strlen($query) > $this->maxQueryLength) {
            throw new \InvalidArgumentException("Query exceeds maximum length of {$this->maxQueryLength} characters");
        }

        // Check query complexity
        $complexity = $this->calculateComplexity($query);
        if ($complexity > $this->maxComplexityScore) {
            throw new \InvalidArgumentException("Query is too complex (score: {$complexity}, max: {$this->maxComplexityScore}). Please simplify your query.");
        }

        // Extract table names from query and validate them
        if (preg_match_all('/FROM\s+`?([a-zA-Z_][a-zA-Z0-9_]*)`?/i', $query, $matches)) {
            $validTables = $this->getValidTables();
            foreach ($matches[1] as $table) {
                $table = $this->sanitizeIdentifier($table);
                if (!empty($validTables) && !in_array($table, $validTables, true)) {
                    throw new \InvalidArgumentException("Table does not exist or is not accessible");
                }
            }
        }
    }

    private function connect(): void
    {
        try {
            $driver = $this->config['driver'] ?? 'mysql';
            $host = $this->config['host'] ?? 'localhost';
            $dbname = $this->config['dbname'] ?? '';
            $username = $this->config['username'] ?? '';
            $password = $this->config['password'] ?? '';
            $port = $this->config['port'] ?? null;

            $dsn = $driver . ':host=' . $host;
            if ($port) {
                $dsn .= ';port=' . $port;
            }
            $dsn .= ';dbname=' . $dbname;
            $dsn .= ';charset=utf8mb4';

            $this->pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            $this->pdo = null;
        }
    }

    public function executeQuery(Request $request, Response $response): Response
    {
        $data = json_decode($request->getBody()->getContents(), true);
        $query = $data['query'] ?? '';
        $params = $data['params'] ?? [];

        if (empty($query)) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Query is required'
            ], JSON_UNESCAPED_SLASHES));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        if (!$this->pdo) {
            $this->connect();
            if (!$this->pdo) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Database connection failed'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(500)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }
        }

        try {
            // Only allow SELECT queries for security
            $trimmedQuery = trim($query);
            if (stripos($trimmedQuery, 'SELECT') !== 0) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Only SELECT queries are allowed'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            // Validate query for dangerous patterns
            $this->validateQuery($query);

            // Set execution time limit
            $startTime = microtime(true);
            $this->pdo->setAttribute(PDO::ATTR_TIMEOUT, $this->maxExecutionTime);

            $stmt = $this->pdo->prepare($query);
            $stmt->execute($params);
            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Check execution time
            $executionTime = microtime(true) - $startTime;
            if ($executionTime > $this->maxExecutionTime) {
                throw new \RuntimeException("Query execution exceeded maximum time limit");
            }

            // Limit result size
            if (count($result) > $this->maxResultRows) {
                $result = array_slice($result, 0, $this->maxResultRows);
            }
            
            $complexity = $this->calculateComplexity($query);
            $response->getBody()->write(json_encode([
                'success' => true,
                'data' => $result,
                'count' => count($result),
                'executionTime' => round($executionTime * 1000, 2), // milliseconds
                'complexity' => $complexity,
                'truncated' => count($result) >= $this->maxResultRows
            ], JSON_UNESCAPED_SLASHES));
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        } catch (\InvalidArgumentException $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => ErrorSanitizer::sanitize($e, 'executeQuery')
            ], JSON_UNESCAPED_SLASHES));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
        } catch (PDOException $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => ErrorSanitizer::sanitize($e, 'executeQuery')
            ], JSON_UNESCAPED_SLASHES));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json; charset=utf-8');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => ErrorSanitizer::sanitize($e, 'executeQuery')
            ], JSON_UNESCAPED_SLASHES));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }
    }

    public function getHistory(Request $request, Response $response): Response
    {
        // Query history would be stored in session or database
        // For now, return empty array
        $response->getBody()->write(json_encode(['history' => []]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}

