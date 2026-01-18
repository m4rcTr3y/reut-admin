<?php
declare(strict_types=1);

namespace Reut\Admin\Services;

use Reut\DB\DataBase;
use Reut\DB\Exceptions\DatabaseConnectionException;
use Reut\Support\ProjectPath;

class DataService
{
    private $db;
    private $config;
    private $validTables = null;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->db = new DataBase($config);
        try {
            $this->db->connect();
        } catch (DatabaseConnectionException $e) {
            throw $e;
        }
    }

    /**
     * Sanitize SQL identifier (table/column name) to prevent SQL injection
     * 
     * @param string $identifier The identifier to sanitize
     * @return string The sanitized identifier
     * @throws \InvalidArgumentException If identifier is invalid
     */
    private function sanitizeIdentifier(string $identifier): string
    {
        // Remove any whitespace
        $identifier = trim($identifier);
        
        // Validate format: must start with letter or underscore, followed by alphanumeric/underscore
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier)) {
            throw new \InvalidArgumentException(
                "Invalid SQL identifier: '{$identifier}'. Only alphanumeric characters and underscores are allowed, and it must start with a letter or underscore."
            );
        }
        
        return $identifier;
    }

    /**
     * Get list of valid table names from database
     * 
     * @return array Array of valid table names
     */
    private function getValidTables(): array
    {
        if ($this->validTables === null) {
            try {
                $dbname = $this->config['dbname'] ?? '';
                $query = "SELECT table_name FROM information_schema.tables WHERE table_schema = :dbname AND table_type = 'BASE TABLE'";
                $result = $this->db->sqlQuery($query, ['dbname' => $dbname]);
                $this->validTables = array_column($result ?? [], 'table_name');
            } catch (\Exception $e) {
                $this->validTables = [];
            }
        }
        return $this->validTables;
    }

    /**
     * Validate table name exists and is safe
     * 
     * @param string $table Table name to validate
     * @throws \InvalidArgumentException If table is invalid or doesn't exist
     */
    private function validateTable(string $table): void
    {
        $table = $this->sanitizeIdentifier($table);
        
        // Check against whitelist of existing tables
        $validTables = $this->getValidTables();
        if (!empty($validTables) && !in_array($table, $validTables, true)) {
            throw new \InvalidArgumentException("Table '{$table}' does not exist or is not accessible");
        }
    }

    public function getTableData(string $table, int $page = 1, int $perPage = 50, array $filters = []): array
    {
        // Validate and sanitize table name
        $this->validateTable($table);
        $table = $this->sanitizeIdentifier($table);
        
        $offset = ($page - 1) * $perPage;
        
        // Build WHERE clause from filters with sanitized column names
        $where = [];
        $params = [];
        foreach ($filters as $field => $value) {
            $field = $this->sanitizeIdentifier($field);
            $where[] = "`{$field}` LIKE :{$field}";
            $params[$field] = "%{$value}%";
        }
        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // Get total count using prepared statement
        $countQuery = "SELECT COUNT(*) as total FROM `{$table}` {$whereClause}";
        $countResult = $this->db->sqlQuery($countQuery, $params);
        $total = $countResult[0]['total'] ?? 0;

        // Get data with sanitized table name
        $query = "SELECT * FROM `{$table}` {$whereClause} LIMIT :limit OFFSET :offset";
        $params['limit'] = $perPage;
        $params['offset'] = $offset;
        
        $data = $this->db->sqlQuery($query, $params);

        return [
            'data' => $data ?? [],
            'pagination' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => (int)$total,
                'totalPages' => ceil($total / $perPage)
            ]
        ];
    }

    public function getRecord(string $table, $id): ?array
    {
        // Validate and sanitize table name
        $this->validateTable($table);
        $table = $this->sanitizeIdentifier($table);
        
        $query = "SELECT * FROM `{$table}` WHERE id = :id LIMIT 1";
        $result = $this->db->sqlQuery($query, ['id' => $id]);
        return $result[0] ?? null;
    }

    public function createRecord(string $table, array $data): array
    {
        // Validate and sanitize table name
        $this->validateTable($table);
        $table = $this->sanitizeIdentifier($table);
        
        // Sanitize all field names
        $sanitizedFields = [];
        foreach (array_keys($data) as $field) {
            $sanitizedFields[] = $this->sanitizeIdentifier($field);
        }
        
        $placeholders = array_map(fn($f) => ":{$f}", $sanitizedFields);
        $fieldsList = '`' . implode('`, `', $sanitizedFields) . '`';
        
        $query = "INSERT INTO `{$table}` ({$fieldsList}) VALUES (" . implode(', ', $placeholders) . ")";
        $this->db->execute($query, $data);
        
        $id = $this->db->lastInsertId();
        return $this->getRecord($table, $id);
    }

    public function updateRecord(string $table, $id, array $data): ?array
    {
        // Validate and sanitize table name
        $this->validateTable($table);
        $table = $this->sanitizeIdentifier($table);
        
        // Sanitize all field names
        $setParts = [];
        foreach (array_keys($data) as $field) {
            $sanitizedField = $this->sanitizeIdentifier($field);
            $setParts[] = "`{$sanitizedField}` = :{$field}";
        }
        $setClause = implode(', ', $setParts);
        
        $query = "UPDATE `{$table}` SET {$setClause} WHERE id = :id";
        $data['id'] = $id;
        $this->db->execute($query, $data);
        
        return $this->getRecord($table, $id);
    }

    public function deleteRecord(string $table, $id): bool
    {
        // Validate and sanitize table name
        $this->validateTable($table);
        $table = $this->sanitizeIdentifier($table);
        
        $query = "DELETE FROM `{$table}` WHERE id = :id";
        $this->db->execute($query, ['id' => $id]);
        return true;
    }
}

