<?php
declare(strict_types=1);

namespace Reut\Admin\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Reut\Admin\Services\AuditService;
use Reut\Support\ProjectPath;

class ModelController
{
    private $config;
    private $auditService;

    public function __construct()
    {
        $projectRoot = ProjectPath::root();
        require $projectRoot . '/config.php';
        $this->config = $config ?? [];
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

    public function listModels(Request $request, Response $response): Response
    {
        ob_start();
        
        try {
            $projectRoot = ProjectPath::root();
            $modelsDir = $projectRoot . '/models';
            $routersDir = $projectRoot . '/routers';
            
            if (!is_dir($modelsDir)) {
                ob_end_clean();
                $response->getBody()->write(json_encode([
                    'models' => [],
                    'total' => 0
                ], JSON_UNESCAPED_SLASHES));
                return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            $modelFiles = glob($modelsDir . '/*Table.php') ?: [];
            $models = [];
            $modelsNamespace = 'Reut\\Models\\';
            
            foreach ($modelFiles as $file) {
                $modelName = basename($file, '.php');
                $routerName = str_replace('Table', '', $modelName) . 'Router.php';
                $routerPath = $routersDir . '/' . $routerName;
                
                // Check if model has relationships or indexes
                $hasRelationships = false;
                $hasIndexes = false;
                
                try {
                    $modelClass = $modelsNamespace . $modelName;
                    if (class_exists($modelClass)) {
                        $instance = new $modelClass($this->config);
                        $instance->connect();
                        
                        // Check for foreign keys (relationships) - both forward and reverse
                        $foreignKeys = method_exists($instance, 'getForeignKeys') ? $instance->getForeignKeys() : [];
                        $tableName = $instance->tableName ?? str_replace('Table', '', $modelName);
                        $reverseFks = $this->getReverseForeignKeys($instance, $tableName);
                        $hasRelationships = !empty($foreignKeys) || !empty($reverseFks);
                        
                        // Check for indexes (non-primary)
                        $tableName = $instance->tableName ?? str_replace('Table', '', $modelName);
                        $indexes = $this->getTableIndexes($instance, $tableName);
                        $hasIndexes = !empty($indexes);
                    }
                } catch (\Exception $e) {
                    // Silently fail - metadata is optional
                }
                
                $models[] = [
                    'name' => $modelName,
                    'filePath' => $file,
                    'hasRouter' => file_exists($routerPath),
                    'routerPath' => $routerPath,
                    'hasRelationships' => $hasRelationships,
                    'hasIndexes' => $hasIndexes
                ];
            }

            ob_end_clean();
            
            $response->getBody()->write(json_encode([
                'models' => $models,
                'total' => count($models)
            ], JSON_UNESCAPED_SLASHES));
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        } catch (\Exception $e) {
            ob_end_clean();
            $response->getBody()->write(json_encode([
                'models' => [],
                'total' => 0,
                'error' => $e->getMessage()
            ], JSON_UNESCAPED_SLASHES));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }
    }

    public function getModel(Request $request, Response $response, array $args): Response
    {
        ob_start();
        
        try {
            $route = $request->getAttribute('route');
            $modelName = $args['name'] ?? ($route ? $route->getArgument('name') : '');
            
            if (empty($modelName)) {
                ob_end_clean();
                $response->getBody()->write(json_encode([
                    'error' => 'Model name is required'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            $projectRoot = ProjectPath::root();
            $modelFile = $projectRoot . '/models/' . $modelName . '.php';

            if (!file_exists($modelFile)) {
                ob_end_clean();
                $response->getBody()->write(json_encode([
                    'error' => 'Model not found'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            $content = file_get_contents($modelFile);
            ob_end_clean();

            $response->getBody()->write(json_encode([
                'name' => $modelName,
                'content' => $content,
                'filePath' => $modelFile
            ], JSON_UNESCAPED_SLASHES));
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        } catch (\Exception $e) {
            ob_end_clean();
            $response->getBody()->write(json_encode([
                'error' => $e->getMessage()
            ], JSON_UNESCAPED_SLASHES));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }
    }

    public function generateRouter(Request $request, Response $response, array $args): Response
    {
        ob_start();
        
        try {
            $route = $request->getAttribute('route');
            $modelName = $args['name'] ?? ($route ? $route->getArgument('name') : '');
            
            if (empty($modelName)) {
                ob_end_clean();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Model name is required'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            // Remove "Table" suffix if present
            $modelNameWithoutTable = str_replace('Table', '', $modelName);
            $routerName = $modelNameWithoutTable . 'Router.php';
            
            $projectRoot = ProjectPath::root();
            $routersDir = $projectRoot . '/routers';
            $routerFile = $routersDir . '/' . $routerName;
            
            // Ensure routers directory exists
            if (!is_dir($routersDir)) {
                mkdir($routersDir, 0755, true);
            }

            // Check if router already exists
            if (file_exists($routerFile)) {
                ob_end_clean();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Router file already exists',
                    'routerPath' => $routerFile
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            // Load config
            require $projectRoot . '/config.php';
            $config = $config ?? [];
            
            // Get auth model name
            $authModelName = $_ENV['AUTH_TABLE'] ?? 'Users';
            
            // Skip if this is the auth model
            if ($modelNameWithoutTable === $authModelName) {
                ob_end_clean();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Cannot generate router for auth model'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            // Generate router file using the same logic as createRoutes.php
            $this->writeRouterFile($routersDir, $modelNameWithoutTable, $config);
            
            // Register the router in routes.php
            $registerRoutesPath = $projectRoot . '/vendor/reut/core/src/registerRoutes.php';
            if (file_exists($registerRoutesPath)) {
                require_once $registerRoutesPath;
                if (function_exists('RegisterRoutes')) {
                    $configDir = $projectRoot . '/routers';
                    RegisterRoutes($configDir, $routersDir);
                }
            }
            
            ob_end_clean();
            
            // Audit log
            $auditInfo = $this->getAuditInfo($request);
            $this->auditService->logModelOperation(
                $auditInfo['userId'],
                'router_generate',
                $modelNameWithoutTable,
                ['router_path' => $routerFile],
                $auditInfo['ipAddress']
            );
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => "Router file generated and registered successfully",
                'routerPath' => $routerFile
            ], JSON_UNESCAPED_SLASHES));
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        } catch (\Exception $e) {
            ob_end_clean();
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ], JSON_UNESCAPED_SLASHES));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }
    }

    private function writeRouterFile(string $routersDir, string $modelName, array $config): void
    {
        $lowercase = strtolower($modelName);
        $routerFile = $routersDir . '/' . $modelName . 'Router.php';
        $classImport = "use Reut\\Models\\{$modelName}Table;";
        
        // Check if model requires auth
        $requiresAuth = false;
        try {
            $modelClass = "Reut\\Models\\{$modelName}Table";
            if (class_exists($modelClass)) {
                $tempInstance = new $modelClass($config);
                $requiresAuth = $tempInstance->requiresAuth ?? false;
            }
        } catch (\Throwable $e) {
            $requiresAuth = false;
        }
        
        $authClass = $requiresAuth ? 'Auth' : 'NoAuth';
        $parentConstructor = $requiresAuth ? 'parent::__construct($app, $config);' : 'parent::__construct($app);';
        $authDescription = $requiresAuth ? 'with' : 'without';
        
        // Use the same template format as createRoutes.php
        $template = <<<EOT
<?php
declare(strict_types=1);
namespace Reut\Routers;

use Slim\App;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Reut\Auth\\{$authClass};
use Reut\Router\ReuteRoute;
use Reut\Query\ReutQueries;

//import the {$modelName} model here

{$classImport}

// {$authClass} class implements endpoints {$authDescription} authentication
class {$modelName}Router extends {$authClass} {
    protected \$config;
     public function __construct(App \$app,Array \$config){
        \$this->config = \$config;
        {$parentConstructor}
    
    }

    protected function genRoutes() {
        \$instance = new {$modelName}Table(\$this->config);
        \$router = ReuteRoute::use(\$this->app);
        
        // Get disabled routes from model instance
        \$disabledRoutes = \$instance->disabledRoutes ?? [];
        \$isAllDisabled = in_array('all', \$disabledRoutes);

        \$router->group('/{$lowercase}', '{$modelName}', function (ReuteRoute \$grouped) use (\$instance, \$disabledRoutes, \$isAllDisabled) {

            //get all {$modelName}s from table " http://endpoint/{$lowercase}/all
            if (!\$isAllDisabled && !in_array('all', \$disabledRoutes)) {
                \$grouped->get('/all', function (Request \$request, Response \$response) use (\$instance) {
                    \$params = \$request->getQueryParams();
                    \$countOnly = isset(\$params['count']) && filter_var(\$params['count'], FILTER_VALIDATE_BOOLEAN);
                    
                    if (\$countOnly) {
                        // Count-only mode: return just the count object
                        \$data = ReutQueries::handleFindAll(\$instance, \$request);
                        // \$data->results should be ['count' => number]
                        \$response->getBody()->write(json_encode(\$data->results));
                    } else {
                        // Normal mode: return paginated results
                        \$page = \$params['page'] ?? 1;
                        \$limit = \$params['limit'] ?? 20;
                        \$data = ReutQueries::handleFindAll(\$instance, \$request)->paginate((int)\$page, (int)\$limit);
                        \$response->getBody()->write(json_encode(\$data));
                    }
                    return \$response->withHeader('Content-Type', 'application/json');
                }, 'List {$modelName} records with pagination');
            }

            //Get single {$modelName} from the table " http://endpoint/{$lowercase}/find/{id}
            if (!\$isAllDisabled && !in_array('find', \$disabledRoutes)) {
                \$grouped->get('/find/{id}',function (Request \$request, Response \$response, \$args) use (\$instance) {
                    \$id = \$args['id'];
                    \$data = ReutQueries::handleFindOne(\$instance, \$id, \$request);
                    \$response->getBody()->write(json_encode(\$data->results));
                    return \$response->withHeader('Content-Type', 'application/json');
                }, 'Find single {$modelName} by id');
            }
            
            //Create new {$modelName}
            if (!\$isAllDisabled && !in_array('add', \$disabledRoutes)) {
                \$grouped->post('/add', function (Request \$request, Response \$response) use (\$instance) {
                    \$input = \$request->getParsedBody();
                    \$result = \$instance->addOne(\$input);
                    \$response->getBody()->write(json_encode(['status' => \$result]));
                    return \$response->withHeader('Content-Type', 'application/json');
                }, 'Create new {$modelName}');
            }

            //Update single {$modelName} from the table " http://endpoint/{$lowercase}/update/id
            if (!\$isAllDisabled && !in_array('update', \$disabledRoutes)) {
                \$grouped->put( '/update/{id}',function (Request \$request, Response \$response, \$args) use (\$instance) {
                    \$id = \$args['id'];
                    \$input = \$request->getParsedBody();
                    return ReutQueries::handleUpdate(\$instance, \$id, \$input, \$request, \$response);
                }, 'Update {$modelName} by id');
            }

            //delete single {$modelName} from the table " http://endpoint/{$lowercase}/delete/id
            if (!\$isAllDisabled && !in_array('delete', \$disabledRoutes)) {
                \$grouped->delete('/delete/{id}', function (Request \$request, Response \$response,\$args) use (\$instance) {
                    \$id = \$args['id'];
                    return ReutQueries::handleDelete(\$instance, \$id, \$request, \$response);
                }, 'Delete {$modelName} by id');
            }

        });
    }
}
EOT;

        file_put_contents($routerFile, $template);
    }

    public function createModel(Request $request, Response $response): Response
    {
        // Implementation would go here - for now just return error
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => 'Model creation not yet implemented'
        ], JSON_UNESCAPED_SLASHES));
        return $response->withStatus(501)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public function updateModel(Request $request, Response $response, array $args): Response
    {
        // Implementation would go here - for now just return error
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => 'Model update not yet implemented'
        ], JSON_UNESCAPED_SLASHES));
        return $response->withStatus(501)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public function deleteModel(Request $request, Response $response, array $args): Response
    {
        ob_start();
        
        try {
            $route = $request->getAttribute('route');
            $modelName = $args['name'] ?? ($route ? $route->getArgument('name') : '');
            
            if (empty($modelName)) {
                ob_end_clean();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Model name is required'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            $projectRoot = ProjectPath::root();
            $modelFile = $projectRoot . '/models/' . $modelName . '.php';

            if (!file_exists($modelFile)) {
                ob_end_clean();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Model not found'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            // Delete the model file
            if (unlink($modelFile)) {
                // Audit log
                $auditInfo = $this->getAuditInfo($request);
                $this->auditService->logModelOperation(
                    $auditInfo['userId'],
                    'model_delete',
                    $modelName,
                    ['file_path' => $modelFile],
                    $auditInfo['ipAddress']
                );
                
                ob_end_clean();
                $response->getBody()->write(json_encode([
                    'success' => true,
                    'message' => 'Model deleted successfully'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
            } else {
                ob_end_clean();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Failed to delete model file'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(500)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }
        } catch (\Exception $e) {
            ob_end_clean();
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ], JSON_UNESCAPED_SLASHES));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }
    }

    public function generateMigration(Request $request, Response $response, array $args): Response
    {
        // Implementation would go here - for now just return error
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => 'Migration generation not yet implemented'
        ], JSON_UNESCAPED_SLASHES));
        return $response->withStatus(501)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public function getRelationships(Request $request, Response $response, array $args): Response
    {
        ob_start();
        
        try {
            $route = $request->getAttribute('route');
            $modelName = $args['name'] ?? ($route ? $route->getArgument('name') : '');
            
            if (empty($modelName)) {
                ob_end_clean();
                $response->getBody()->write(json_encode([
                    'relationships' => [],
                    'error' => 'Model name is required'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            $projectRoot = ProjectPath::root();
            
            // Handle model name - it might already include "Table" suffix
            $baseModelName = str_replace('Table', '', $modelName);
            $modelClass = 'Reut\\Models\\' . $baseModelName . 'Table';
            
            // Try to load the model file if class doesn't exist
            if (!class_exists($modelClass)) {
                $modelFile = $projectRoot . '/models/' . $modelName . '.php';
                if (file_exists($modelFile)) {
                    require_once $modelFile;
                }
            }
            
            if (!class_exists($modelClass)) {
                ob_end_clean();
                $response->getBody()->write(json_encode([
                    'relationships' => [],
                    'error' => 'Model not found: ' . $modelClass
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            $instance = new $modelClass($this->config);
            $instance->connect();
            
            // Get foreign keys from model
            $foreignKeys = method_exists($instance, 'getForeignKeys') ? $instance->getForeignKeys() : [];
            
            // Get reverse foreign keys (tables that reference this table)
            $tableName = $instance->tableName ?? $modelName;
            $reverseFks = $this->getReverseForeignKeys($instance, $tableName);
            
            ob_end_clean();
            
            $response->getBody()->write(json_encode([
                'relationships' => [
                    'foreignKeys' => $foreignKeys,
                    'reverseForeignKeys' => $reverseFks
                ]
            ], JSON_UNESCAPED_SLASHES));
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        } catch (\Exception $e) {
            ob_end_clean();
            $response->getBody()->write(json_encode([
                'relationships' => [],
                'error' => $e->getMessage()
            ], JSON_UNESCAPED_SLASHES));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }
    }

    public function addRelationship(Request $request, Response $response, array $args): Response
    {
        ob_start();
        
        try {
            $route = $request->getAttribute('route');
            $modelName = $args['name'] ?? ($route ? $route->getArgument('name') : '');
            $data = json_decode($request->getBody()->getContents(), true);
            
            if (empty($modelName)) {
                ob_end_clean();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Model name is required'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            $column = $data['column'] ?? '';
            $referencedTable = $data['referenced_table'] ?? '';
            $referencedColumn = $data['referenced_column'] ?? 'id';
            $onDelete = $data['on_delete'] ?? 'CASCADE';
            $onUpdate = $data['on_update'] ?? 'CASCADE';

            if (empty($column) || empty($referencedTable)) {
                ob_end_clean();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Column and referenced table are required'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            $projectRoot = ProjectPath::root();
            $modelFile = $projectRoot . '/models/' . $modelName . '.php';
            
            if (!file_exists($modelFile)) {
                ob_end_clean();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Model file not found'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            // Read model file
            $content = file_get_contents($modelFile);
            
            // Check if foreign key already exists
            if (strpos($content, "addForeignKey('{$column}'") !== false) {
                ob_end_clean();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Foreign key for this column already exists'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            // Find where to add the foreign key (after column definition)
            $pattern = "/addColumn\('{$column}'[^;]+\);/";
            $replacement = "$0\n        \$this->addForeignKey('{$column}', '{$referencedTable}', '{$referencedColumn}', '{$onDelete}', '{$onUpdate}');";
            
            $newContent = preg_replace($pattern, $replacement, $content);
            
            if ($newContent === $content) {
                ob_end_clean();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Could not find column definition to add foreign key'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            file_put_contents($modelFile, $newContent);
            
            // Audit log
            $auditInfo = $this->getAuditInfo($request);
            $this->auditService->logModelOperation(
                $auditInfo['userId'],
                'relationship_add',
                $modelName,
                [
                    'column' => $column,
                    'referenced_table' => $referencedTable,
                    'referenced_column' => $referencedColumn
                ],
                $auditInfo['ipAddress']
            );
            
            ob_end_clean();
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Relationship added successfully'
            ], JSON_UNESCAPED_SLASHES));
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        } catch (\Exception $e) {
            ob_end_clean();
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ], JSON_UNESCAPED_SLASHES));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }
    }

    public function getIndexes(Request $request, Response $response, array $args): Response
    {
        ob_start();
        
        try {
            $route = $request->getAttribute('route');
            $modelName = $args['name'] ?? ($route ? $route->getArgument('name') : '');
            
            if (empty($modelName)) {
                ob_end_clean();
                $response->getBody()->write(json_encode([
                    'indexes' => [],
                    'error' => 'Model name is required'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            $projectRoot = ProjectPath::root();
            
            // Handle model name - it might already include "Table" suffix
            $baseModelName = str_replace('Table', '', $modelName);
            $modelClass = 'Reut\\Models\\' . $baseModelName . 'Table';
            
            // Try to load the model file if class doesn't exist
            if (!class_exists($modelClass)) {
                $modelFile = $projectRoot . '/models/' . $modelName . '.php';
                if (file_exists($modelFile)) {
                    require_once $modelFile;
                }
            }
            
            if (!class_exists($modelClass)) {
                ob_end_clean();
                $response->getBody()->write(json_encode([
                    'indexes' => [],
                    'error' => 'Model not found: ' . $modelClass
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            $instance = new $modelClass($this->config);
            $instance->connect();
            
            $tableName = $instance->tableName ?? $modelName;
            $indexes = $this->getTableIndexes($instance, $tableName);
            
            ob_end_clean();
            
            $response->getBody()->write(json_encode([
                'indexes' => $indexes
            ], JSON_UNESCAPED_SLASHES));
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        } catch (\Exception $e) {
            ob_end_clean();
            $response->getBody()->write(json_encode([
                'indexes' => [],
                'error' => $e->getMessage()
            ], JSON_UNESCAPED_SLASHES));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }
    }

    public function createIndex(Request $request, Response $response, array $args): Response
    {
        ob_start();
        
        try {
            $route = $request->getAttribute('route');
            $modelName = $args['name'] ?? ($route ? $route->getArgument('name') : '');
            $data = json_decode($request->getBody()->getContents(), true);
            
            if (empty($modelName)) {
                ob_end_clean();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Model name is required'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            $columns = $data['columns'] ?? [];
            $indexName = $data['index_name'] ?? '';
            $isUnique = (bool)($data['unique'] ?? false);

            if (empty($columns) || !is_array($columns)) {
                ob_end_clean();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Columns are required'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            $projectRoot = ProjectPath::root();
            $modelClass = 'Reut\\Models\\' . $modelName . 'Table';
            
            if (!class_exists($modelClass)) {
                ob_end_clean();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Model not found'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            $instance = new $modelClass($this->config);
            $instance->connect();
            
            $tableName = $instance->tableName ?? $modelName;
            
            // Generate index name if not provided
            if (empty($indexName)) {
                $indexName = 'idx_' . strtolower($tableName) . '_' . implode('_', $columns);
            }

            // Create index
            $columnsList = '`' . implode('`, `', $columns) . '`';
            $uniqueKeyword = $isUnique ? 'UNIQUE' : '';
            
            $sql = "CREATE {$uniqueKeyword} INDEX `{$indexName}` ON `{$tableName}` ({$columnsList})";
            $instance->sqlQuery($sql, []);
            
            // Audit log
            $auditInfo = $this->getAuditInfo($request);
            $this->auditService->logModelOperation(
                $auditInfo['userId'],
                'index_create',
                $modelName,
                [
                    'index_name' => $indexName,
                    'columns' => $columns,
                    'unique' => $isUnique
                ],
                $auditInfo['ipAddress']
            );
            
            ob_end_clean();
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Index created successfully',
                'index_name' => $indexName
            ], JSON_UNESCAPED_SLASHES));
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        } catch (\Exception $e) {
            ob_end_clean();
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ], JSON_UNESCAPED_SLASHES));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }
    }

    public function deleteIndex(Request $request, Response $response, array $args): Response
    {
        ob_start();
        
        try {
            $route = $request->getAttribute('route');
            $modelName = $args['name'] ?? ($route ? $route->getArgument('name') : '');
            $indexName = $args['index_name'] ?? '';
            
            if (empty($modelName) || empty($indexName)) {
                ob_end_clean();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Model name and index name are required'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            $projectRoot = ProjectPath::root();
            $modelClass = 'Reut\\Models\\' . $modelName . 'Table';
            
            if (!class_exists($modelClass)) {
                ob_end_clean();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Model not found'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            $instance = new $modelClass($this->config);
            $instance->connect();
            
            $tableName = $instance->tableName ?? $modelName;
            
            // Drop index
            $sql = "DROP INDEX `{$indexName}` ON `{$tableName}`";
            $instance->sqlQuery($sql, []);
            
            // Audit log
            $auditInfo = $this->getAuditInfo($request);
            $this->auditService->logModelOperation(
                $auditInfo['userId'],
                'index_delete',
                $modelName,
                ['index_name' => $indexName],
                $auditInfo['ipAddress']
            );
            
            ob_end_clean();
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Index deleted successfully'
            ], JSON_UNESCAPED_SLASHES));
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        } catch (\Exception $e) {
            ob_end_clean();
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ], JSON_UNESCAPED_SLASHES));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }
    }

    public function getColumns(Request $request, Response $response, array $args): Response
    {
        ob_start();
        
        try {
            $route = $request->getAttribute('route');
            $modelName = $args['name'] ?? ($route ? $route->getArgument('name') : '');
            
            if (empty($modelName)) {
                ob_end_clean();
                $response->getBody()->write(json_encode([
                    'columns' => [],
                    'error' => 'Model name is required'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            $projectRoot = ProjectPath::root();
            $modelFile = $projectRoot . '/models/' . $modelName . '.php';
            
            if (!file_exists($modelFile)) {
                ob_end_clean();
                $response->getBody()->write(json_encode([
                    'columns' => [],
                    'error' => 'Model file not found'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            $content = file_get_contents($modelFile);
            $columns = $this->parseModelColumns($content);
            
            // Remove position from response
            foreach ($columns as &$column) {
                unset($column['position']);
            }
            
            ob_end_clean();
            $response->getBody()->write(json_encode([
                'columns' => $columns
            ], JSON_UNESCAPED_SLASHES));
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        } catch (\Exception $e) {
            ob_end_clean();
            $response->getBody()->write(json_encode([
                'columns' => [],
                'error' => $e->getMessage()
            ], JSON_UNESCAPED_SLASHES));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }
    }

    public function addColumn(Request $request, Response $response, array $args): Response
    {
        ob_start();
        
        try {
            $route = $request->getAttribute('route');
            $modelName = $args['name'] ?? ($route ? $route->getArgument('name') : '');
            
            // Try getParsedBody first (if BodyParsingMiddleware has already parsed it)
            $data = $request->getParsedBody();
            if (empty($data) || !is_array($data)) {
                // Fallback to manual JSON decode
                $body = $request->getBody()->getContents();
                $data = json_decode($body, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    ob_end_clean();
                    $response->getBody()->write(json_encode([
                        'success' => false,
                        'error' => 'Invalid JSON in request body: ' . json_last_error_msg()
                    ], JSON_UNESCAPED_SLASHES));
                    return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
                }
            }
            
            if (empty($modelName)) {
                ob_end_clean();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Model name is required'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            if (empty($data) || !is_array($data)) {
                ob_end_clean();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Request body is required and must be valid JSON'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            $columnName = trim($data['name'] ?? '');
            $columnType = $data['type'] ?? '';
            $parameters = $data['parameters'] ?? [];

            if (empty($columnName) || empty($columnType)) {
                ob_end_clean();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Column name and type are required'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            // Validate column name
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $columnName)) {
                ob_end_clean();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Invalid column name. Must start with letter or underscore and contain only alphanumeric characters and underscores.'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            $projectRoot = ProjectPath::root();
            $modelFile = $projectRoot . '/models/' . $modelName . '.php';
            
            if (!file_exists($modelFile)) {
                ob_end_clean();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Model file not found'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            // Check if column already exists
            $content = file_get_contents($modelFile);
            if (preg_match('/\$this->addColumn\s*\(\s*[\'"]' . preg_quote($columnName, '/') . '[\'"]/', $content)) {
                ob_end_clean();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Column already exists'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            // Generate column code
            $columnData = [
                'name' => $columnName,
                'type' => $columnType,
                'parameters' => $parameters
            ];
            $columnCode = $this->generateColumnCode($columnData);

            // Insert column into file
            if (!$this->insertColumnIntoFile($modelFile, $columnCode)) {
                ob_end_clean();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Failed to add column to model file'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(500)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            // Audit log
            $auditInfo = $this->getAuditInfo($request);
            $this->auditService->logModelOperation(
                $auditInfo['userId'],
                'column_add',
                $modelName,
                [
                    'column_name' => $columnName,
                    'column_type' => $columnType,
                    'parameters' => $parameters
                ],
                $auditInfo['ipAddress']
            );
            
            ob_end_clean();
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Column added successfully'
            ], JSON_UNESCAPED_SLASHES));
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        } catch (\Exception $e) {
            ob_end_clean();
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ], JSON_UNESCAPED_SLASHES));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }
    }

    public function updateColumn(Request $request, Response $response, array $args): Response
    {
        ob_start();
        
        try {
            $route = $request->getAttribute('route');
            $modelName = $args['name'] ?? ($route ? $route->getArgument('name') : '');
            $columnName = $args['columnName'] ?? '';
            
            // Try getParsedBody first (if BodyParsingMiddleware has already parsed it)
            $data = $request->getParsedBody();
            if (empty($data) || !is_array($data)) {
                // Fallback to manual JSON decode
                $body = $request->getBody()->getContents();
                $data = json_decode($body, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    ob_end_clean();
                    $response->getBody()->write(json_encode([
                        'success' => false,
                        'error' => 'Invalid JSON in request body: ' . json_last_error_msg()
                    ], JSON_UNESCAPED_SLASHES));
                    return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
                }
            }
            
            if (empty($modelName) || empty($columnName)) {
                ob_end_clean();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Model name and column name are required'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            if (empty($data) || !is_array($data)) {
                ob_end_clean();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Request body is required and must be valid JSON'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            $newColumnName = trim($data['name'] ?? $columnName);
            $columnType = $data['type'] ?? '';
            $parameters = $data['parameters'] ?? [];

            if (empty($newColumnName) || empty($columnType)) {
                ob_end_clean();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Column name and type are required'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            // Validate column name
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $newColumnName)) {
                ob_end_clean();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Invalid column name. Must start with letter or underscore and contain only alphanumeric characters and underscores.'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            $projectRoot = ProjectPath::root();
            $modelFile = $projectRoot . '/models/' . $modelName . '.php';
            
            if (!file_exists($modelFile)) {
                ob_end_clean();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Model file not found'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            // Check if old column exists
            $content = file_get_contents($modelFile);
            if (!preg_match('/\$this->addColumn\s*\(\s*[\'"]' . preg_quote($columnName, '/') . '[\'"]/', $content)) {
                ob_end_clean();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Column not found'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            // If name changed, check if new name already exists
            if ($newColumnName !== $columnName) {
                if (preg_match('/\$this->addColumn\s*\(\s*[\'"]' . preg_quote($newColumnName, '/') . '[\'"]/', $content)) {
                    ob_end_clean();
                    $response->getBody()->write(json_encode([
                        'success' => false,
                        'error' => 'Column with new name already exists'
                    ], JSON_UNESCAPED_SLASHES));
                    return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
                }
            }

            // Generate new column code
            $columnData = [
                'name' => $newColumnName,
                'type' => $columnType,
                'parameters' => $parameters
            ];
            $columnCode = $this->generateColumnCode($columnData);

            // Update column in file
            if (!$this->updateColumnInFile($modelFile, $columnName, $columnCode)) {
                ob_end_clean();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Failed to update column in model file'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(500)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            // Audit log
            $auditInfo = $this->getAuditInfo($request);
            $this->auditService->logModelOperation(
                $auditInfo['userId'],
                'column_update',
                $modelName,
                [
                    'old_column_name' => $columnName,
                    'new_column_name' => $newColumnName,
                    'column_type' => $columnType,
                    'parameters' => $parameters
                ],
                $auditInfo['ipAddress']
            );
            
            ob_end_clean();
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Column updated successfully'
            ], JSON_UNESCAPED_SLASHES));
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        } catch (\Exception $e) {
            ob_end_clean();
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ], JSON_UNESCAPED_SLASHES));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }
    }

    public function deleteColumn(Request $request, Response $response, array $args): Response
    {
        ob_start();
        
        try {
            $route = $request->getAttribute('route');
            $modelName = $args['name'] ?? ($route ? $route->getArgument('name') : '');
            $columnName = $args['columnName'] ?? '';
            
            if (empty($modelName) || empty($columnName)) {
                ob_end_clean();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Model name and column name are required'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            $projectRoot = ProjectPath::root();
            $modelFile = $projectRoot . '/models/' . $modelName . '.php';
            
            if (!file_exists($modelFile)) {
                ob_end_clean();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Model file not found'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            // Check if column exists
            $content = file_get_contents($modelFile);
            if (!preg_match('/\$this->addColumn\s*\(\s*[\'"]' . preg_quote($columnName, '/') . '[\'"]/', $content)) {
                ob_end_clean();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Column not found'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            // Delete column from file
            if (!$this->deleteColumnFromFile($modelFile, $columnName)) {
                ob_end_clean();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Failed to delete column from model file'
                ], JSON_UNESCAPED_SLASHES));
                return $response->withStatus(500)->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            // Audit log
            $auditInfo = $this->getAuditInfo($request);
            $this->auditService->logModelOperation(
                $auditInfo['userId'],
                'column_delete',
                $modelName,
                ['column_name' => $columnName],
                $auditInfo['ipAddress']
            );
            
            ob_end_clean();
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Column deleted successfully'
            ], JSON_UNESCAPED_SLASHES));
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        } catch (\Exception $e) {
            ob_end_clean();
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ], JSON_UNESCAPED_SLASHES));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json; charset=utf-8');
        }
    }

    private function getReverseForeignKeys($instance, string $tableName): array
    {
        try {
            if (!$instance->pdo) {
                return [];
            }

            // Get database name from config
            $dbName = $this->config['db']['database'] ?? $this->config['database'] ?? null;
            
            if (!$dbName) {
                // Try to get it from the connection
                $stmt = $instance->pdo->query("SELECT DATABASE() as db_name");
                $result = $stmt->fetch(\PDO::FETCH_ASSOC);
                $dbName = $result['db_name'] ?? null;
            }
            
            if (!$dbName) {
                return [];
            }

            $stmt = $instance->pdo->prepare("
                SELECT 
                    TABLE_NAME as table_name,
                    COLUMN_NAME as column_name,
                    CONSTRAINT_NAME as constraint_name,
                    REFERENCED_TABLE_NAME as referenced_table_name,
                    REFERENCED_COLUMN_NAME as referenced_column_name
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
                WHERE TABLE_SCHEMA = :dbName 
                AND REFERENCED_TABLE_NAME = :tableName
            ");
            $stmt->execute([
                'dbName' => $dbName,
                'tableName' => $tableName
            ]);
            
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            error_log("Error getting reverse foreign keys: " . $e->getMessage());
            return [];
        }
    }

    private function getTableIndexes($instance, string $tableName): array
    {
        try {
            if (!$instance->pdo) {
                return [];
            }

            // Get database name from config
            $dbName = $this->config['db']['database'] ?? $this->config['database'] ?? null;
            
            if (!$dbName) {
                // Try to get it from the connection
                $stmt = $instance->pdo->query("SELECT DATABASE() as db_name");
                $result = $stmt->fetch(\PDO::FETCH_ASSOC);
                $dbName = $result['db_name'] ?? null;
            }
            
            if (!$dbName) {
                return [];
            }

            $stmt = $instance->pdo->prepare("
                SELECT 
                    INDEX_NAME as index_name,
                    COLUMN_NAME as column_name,
                    NON_UNIQUE as non_unique,
                    SEQ_IN_INDEX as seq_in_index
                FROM INFORMATION_SCHEMA.STATISTICS 
                WHERE TABLE_SCHEMA = :dbName 
                AND TABLE_NAME = :tableName
                AND INDEX_NAME != 'PRIMARY'
                ORDER BY INDEX_NAME, SEQ_IN_INDEX
            ");
            $stmt->execute([
                'dbName' => $dbName,
                'tableName' => $tableName
            ]);
            
            $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            // Group by index name
            $indexes = [];
            foreach ($results as $row) {
                $indexName = $row['index_name'];
                if (!isset($indexes[$indexName])) {
                    $indexes[$indexName] = [
                        'name' => $indexName,
                        'columns' => [],
                        'unique' => (int)$row['non_unique'] === 0
                    ];
                }
                $indexes[$indexName]['columns'][] = $row['column_name'];
            }
            
            return array_values($indexes);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Parse column definitions from model file content
     */
    private function parseModelColumns(string $content): array
    {
        $columns = [];
        
        // Pattern to match addColumn calls: $this->addColumn('name', new Type(...));
        // This regex handles multi-line definitions
        $pattern = '/\$this->addColumn\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*new\s+(\w+)\s*\(([^)]*)\)\s*\)\s*;/s';
        
        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $match) {
                $columnName = $match[1][0];
                $typeName = $match[2][0];
                $paramsStr = trim($match[3][0]);
                
                // Parse parameters
                $params = $this->parseColumnParameters($paramsStr, $typeName);
                
                $columns[] = [
                    'name' => $columnName,
                    'type' => $typeName,
                    'parameters' => $params,
                    'position' => $match[0][1] // Offset in file
                ];
            }
        }
        
        return $columns;
    }

    /**
     * Parse column type parameters from string
     */
    private function parseColumnParameters(string $paramsStr, string $typeName): array
    {
        $params = [];
        
        if (empty($paramsStr)) {
            return $params;
        }
        
        // Split by comma, but respect nested parentheses and quotes
        $tokens = $this->tokenizeParameters($paramsStr);
        
        // Map tokens to parameter names based on type
        switch ($typeName) {
            case 'Varchar':
                $params['length'] = isset($tokens[0]) ? (int)$tokens[0] : 255;
                $params['nullable'] = isset($tokens[1]) ? $this->parseBoolean($tokens[1]) : true;
                $params['default'] = isset($tokens[2]) ? $this->parseValue($tokens[2]) : null;
                $params['isPrimary'] = isset($tokens[3]) ? $this->parseBoolean($tokens[3]) : false;
                break;
                
            case 'Integer':
            case 'BigInteger':
            case 'SmallInteger':
            case 'TinyInteger':
                $params['nullable'] = isset($tokens[0]) ? $this->parseBoolean($tokens[0]) : true;
                $params['isPrimary'] = isset($tokens[1]) ? $this->parseBoolean($tokens[1]) : false;
                $params['autoIncrement'] = isset($tokens[2]) ? $this->parseBoolean($tokens[2]) : false;
                $params['default'] = isset($tokens[3]) ? $this->parseValue($tokens[3]) : null;
                break;
                
            case 'Decimal':
                $params['precision'] = isset($tokens[0]) ? (int)$tokens[0] : 10;
                $params['scale'] = isset($tokens[1]) ? (int)$tokens[1] : 2;
                $params['nullable'] = isset($tokens[2]) ? $this->parseBoolean($tokens[2]) : true;
                $params['default'] = isset($tokens[3]) ? $this->parseValue($tokens[3]) : null;
                $params['isPrimary'] = isset($tokens[4]) ? $this->parseBoolean($tokens[4]) : false;
                break;
                
            case 'Timestamp':
                $params['nullable'] = isset($tokens[0]) ? $this->parseBoolean($tokens[0]) : true;
                $params['useCurrent'] = isset($tokens[1]) ? $this->parseBoolean($tokens[1]) : true;
                $params['onUpdate'] = isset($tokens[2]) ? $this->parseBoolean($tokens[2]) : false;
                $params['isPrimary'] = isset($tokens[3]) ? $this->parseBoolean($tokens[3]) : false;
                break;
                
            case 'Text':
            case 'Boolean':
            case 'Date':
            case 'DateTimeType':
            case 'TimeType':
            case 'Json':
            case 'Blob':
            case 'FloatType':
            case 'DoubleType':
                $params['nullable'] = isset($tokens[0]) ? $this->parseBoolean($tokens[0]) : true;
                $params['default'] = isset($tokens[1]) ? $this->parseValue($tokens[1]) : null;
                $params['isPrimary'] = isset($tokens[2]) ? $this->parseBoolean($tokens[2]) : false;
                break;
                
            case 'EnumType':
                // EnumType has array as first param
                if (isset($tokens[0]) && strpos($tokens[0], '[') !== false) {
                    $params['values'] = $this->parseArray($tokens[0]);
                }
                $params['nullable'] = isset($tokens[1]) ? $this->parseBoolean($tokens[1]) : true;
                $params['default'] = isset($tokens[2]) ? $this->parseValue($tokens[2]) : null;
                $params['isPrimary'] = isset($tokens[3]) ? $this->parseBoolean($tokens[3]) : false;
                break;
        }
        
        return $params;
    }

    /**
     * Tokenize parameter string respecting quotes and parentheses
     */
    private function tokenizeParameters(string $str): array
    {
        $tokens = [];
        $current = '';
        $depth = 0;
        $inString = false;
        $stringChar = '';
        
        for ($i = 0; $i < strlen($str); $i++) {
            $char = $str[$i];
            
            if (!$inString && ($char === '"' || $char === "'")) {
                $inString = true;
                $stringChar = $char;
                $current .= $char;
            } elseif ($inString && $char === $stringChar && ($i === 0 || $str[$i-1] !== '\\')) {
                $inString = false;
                $current .= $char;
            } elseif (!$inString && $char === '(') {
                $depth++;
                $current .= $char;
            } elseif (!$inString && $char === ')') {
                $depth--;
                $current .= $char;
            } elseif (!$inString && $char === ',' && $depth === 0) {
                $tokens[] = trim($current);
                $current = '';
            } else {
                $current .= $char;
            }
        }
        
        if (trim($current) !== '') {
            $tokens[] = trim($current);
        }
        
        return $tokens;
    }

    /**
     * Parse boolean value from string
     */
    private function parseBoolean(string $value): bool
    {
        $value = trim($value);
        return in_array(strtolower($value), ['true', '1', 'yes'], true);
    }

    /**
     * Parse value (string, number, null)
     */
    private function parseValue(string $value): mixed
    {
        $value = trim($value);
        
        if ($value === 'null' || $value === 'NULL') {
            return null;
        }
        
        if (($value[0] === '"' && substr($value, -1) === '"') || 
            ($value[0] === "'" && substr($value, -1) === "'")) {
            return substr($value, 1, -1);
        }
        
        if (is_numeric($value)) {
            return strpos($value, '.') !== false ? (float)$value : (int)$value;
        }
        
        return $value;
    }

    /**
     * Parse array from string (for EnumType)
     */
    private function parseArray(string $str): array
    {
        // Simple array parsing - extract values between brackets
        if (preg_match('/\[(.*)\]/s', $str, $matches)) {
            $content = $matches[1];
            $values = [];
            $current = '';
            $inString = false;
            $stringChar = '';
            
            for ($i = 0; $i < strlen($content); $i++) {
                $char = $content[$i];
                
                if (!$inString && ($char === '"' || $char === "'")) {
                    $inString = true;
                    $stringChar = $char;
                } elseif ($inString && $char === $stringChar && ($i === 0 || $content[$i-1] !== '\\')) {
                    $inString = false;
                    $values[] = trim($current, $stringChar);
                    $current = '';
                } elseif (!$inString && $char === ',') {
                    if (trim($current) !== '') {
                        $values[] = trim($current, " \t\n\r\"'");
                    }
                    $current = '';
                } else {
                    $current .= $char;
                }
            }
            
            if (trim($current) !== '') {
                $values[] = trim($current, " \t\n\r\"'");
            }
            
            return array_filter($values);
        }
        
        return [];
    }

    /**
     * Generate PHP code for addColumn call
     */
    private function generateColumnCode(array $columnData): string
    {
        $name = $columnData['name'];
        $type = $columnData['type'];
        $params = $columnData['parameters'] ?? [];
        
        $typeParams = [];
        
        switch ($type) {
            case 'Varchar':
                $length = $params['length'] ?? 255;
                $nullable = $params['nullable'] ?? true;
                $default = $params['default'] ?? null;
                $isPrimary = $params['isPrimary'] ?? false;
                
                $typeParams[] = $length;
                $typeParams[] = $nullable ? 'true' : 'false';
                if ($default !== null) {
                    $typeParams[] = var_export($default, true);
                }
                if ($isPrimary) {
                    $typeParams[] = 'true';
                }
                break;
                
            case 'Integer':
            case 'BigInteger':
            case 'SmallInteger':
            case 'TinyInteger':
                $nullable = $params['nullable'] ?? true;
                $isPrimary = $params['isPrimary'] ?? false;
                $autoIncrement = $params['autoIncrement'] ?? false;
                $default = $params['default'] ?? null;
                
                $typeParams[] = $nullable ? 'true' : 'false';
                $typeParams[] = $isPrimary ? 'true' : 'false';
                $typeParams[] = $autoIncrement ? 'true' : 'false';
                if ($default !== null) {
                    $typeParams[] = var_export($default, true);
                } else {
                    $typeParams[] = 'null';
                }
                break;
                
            case 'Decimal':
                $precision = $params['precision'] ?? 10;
                $scale = $params['scale'] ?? 2;
                $nullable = $params['nullable'] ?? true;
                $default = $params['default'] ?? null;
                $isPrimary = $params['isPrimary'] ?? false;
                
                $typeParams[] = $precision;
                $typeParams[] = $scale;
                $typeParams[] = $nullable ? 'true' : 'false';
                if ($default !== null) {
                    $typeParams[] = var_export($default, true);
                }
                if ($isPrimary) {
                    $typeParams[] = 'true';
                }
                break;
                
            case 'Timestamp':
                $nullable = $params['nullable'] ?? true;
                $useCurrent = $params['useCurrent'] ?? true;
                $onUpdate = $params['onUpdate'] ?? false;
                $isPrimary = $params['isPrimary'] ?? false;
                
                $typeParams[] = $nullable ? 'true' : 'false';
                $typeParams[] = $useCurrent ? 'true' : 'false';
                $typeParams[] = $onUpdate ? 'true' : 'false';
                if ($isPrimary) {
                    $typeParams[] = 'true';
                }
                break;
                
            case 'EnumType':
                $values = $params['values'] ?? [];
                $nullable = $params['nullable'] ?? true;
                $default = $params['default'] ?? null;
                $isPrimary = $params['isPrimary'] ?? false;
                
                $valuesStr = '[' . implode(', ', array_map(fn($v) => var_export($v, true), $values)) . ']';
                $typeParams[] = $valuesStr;
                $typeParams[] = $nullable ? 'true' : 'false';
                if ($default !== null) {
                    $typeParams[] = var_export($default, true);
                }
                if ($isPrimary) {
                    $typeParams[] = 'true';
                }
                break;
                
            case 'Text':
            case 'Boolean':
            case 'Date':
            case 'DateTimeType':
            case 'TimeType':
            case 'Json':
            case 'Blob':
            case 'FloatType':
            case 'DoubleType':
                $nullable = $params['nullable'] ?? true;
                $default = $params['default'] ?? null;
                $isPrimary = $params['isPrimary'] ?? false;
                
                $typeParams[] = $nullable ? 'true' : 'false';
                if ($default !== null) {
                    $typeParams[] = var_export($default, true);
                }
                if ($isPrimary) {
                    $typeParams[] = 'true';
                }
                break;
        }
        
        $paramsStr = implode(', ', $typeParams);
        $typeClass = $type;
        
        // Handle multi-line for complex types
        if (strlen($paramsStr) > 60) {
            $paramsStr = "\n            " . implode(",\n            ", $typeParams) . "\n        ";
        }
        
        return "        \$this->addColumn('{$name}', new {$typeClass}({$paramsStr}));";
    }

    /**
     * Insert column into model file
     */
    private function insertColumnIntoFile(string $filePath, string $columnCode, ?int $position = null): bool
    {
        $content = file_get_contents($filePath);
        
        if ($position !== null) {
            // Insert at specific position
            $before = substr($content, 0, $position);
            $after = substr($content, $position);
            
            // Find the end of the previous line
            $lastNewline = strrpos($before, "\n");
            if ($lastNewline !== false) {
                $indent = '';
                $lineStart = $lastNewline + 1;
                $lineContent = substr($before, $lineStart);
                // Extract indentation
                if (preg_match('/^(\s+)/', $lineContent, $matches)) {
                    $indent = $matches[1];
                }
                $columnCode = $indent . trim($columnCode) . "\n";
            }
            
            $newContent = $before . $columnCode . $after;
        } else {
            // Find insertion point: after last addColumn, before relationships
            $pattern = '/(\$this->addColumn\([^;]+\);)/s';
            preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE);
            
            if (!empty($matches[0])) {
                $lastMatch = end($matches[0]);
                $insertPos = $lastMatch[1] + strlen($lastMatch[0]);
                
                // Find end of line
                $lineEnd = strpos($content, "\n", $insertPos);
                if ($lineEnd !== false) {
                    $insertPos = $lineEnd + 1;
                }
                
                // Get indentation from previous line
                $lineStart = strrpos(substr($content, 0, $insertPos), "\n");
                if ($lineStart !== false) {
                    $lineContent = substr($content, $lineStart + 1, $insertPos - $lineStart - 1);
                    if (preg_match('/^(\s+)/', $lineContent, $indentMatch)) {
                        $indent = $indentMatch[1];
                        $columnCode = $indent . trim($columnCode) . "\n";
                    }
                }
                
                $newContent = substr($content, 0, $insertPos) . $columnCode . substr($content, $insertPos);
            } else {
                // No columns found, insert after constructor
                $pattern = '/parent::__construct\([^)]+\);/s';
                if (preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                    $insertPos = $matches[0][1] + strlen($matches[0][0]);
                    $lineEnd = strpos($content, "\n", $insertPos);
                    if ($lineEnd !== false) {
                        $insertPos = $lineEnd + 1;
                    }
                    
                    // Get indentation
                    $lineStart = strrpos(substr($content, 0, $insertPos), "\n");
                    if ($lineStart !== false) {
                        $lineContent = substr($content, $lineStart + 1, $insertPos - $lineStart - 1);
                        if (preg_match('/^(\s+)/', $lineContent, $indentMatch)) {
                            $indent = $indentMatch[1];
                            $columnCode = "\n" . $indent . trim($columnCode) . "\n";
                        }
                    }
                    
                    $newContent = substr($content, 0, $insertPos) . $columnCode . substr($content, $insertPos);
                } else {
                    return false;
                }
            }
        }
        
        return file_put_contents($filePath, $newContent) !== false;
    }

    /**
     * Update column in model file
     */
    private function updateColumnInFile(string $filePath, string $oldColumnName, string $newColumnCode): bool
    {
        $content = file_get_contents($filePath);
        
        // Pattern to match the entire addColumn call for this column
        $pattern = '/\$this->addColumn\s*\(\s*[\'"]' . preg_quote($oldColumnName, '/') . '[\'"]\s*,\s*new\s+\w+\s*\([^)]*\)\s*\)\s*;/s';
        
        if (preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            $matchStart = $matches[0][1];
            $matchEnd = $matchStart + strlen($matches[0][0]);
            
            // Get indentation from the matched line
            $lineStart = strrpos(substr($content, 0, $matchStart), "\n");
            if ($lineStart !== false) {
                $lineContent = substr($content, $lineStart + 1, $matchStart - $lineStart - 1);
                if (preg_match('/^(\s+)/', $lineContent, $indentMatch)) {
                    $indent = $indentMatch[1];
                    $newColumnCode = $indent . trim($newColumnCode) . "\n";
                }
            }
            
            $newContent = substr($content, 0, $matchStart) . $newColumnCode . substr($content, $matchEnd);
            
            return file_put_contents($filePath, $newContent) !== false;
        }
        
        return false;
    }

    /**
     * Delete column from model file
     */
    private function deleteColumnFromFile(string $filePath, string $columnName): bool
    {
        $content = file_get_contents($filePath);
        
        // Remove addColumn call
        $pattern = '/\$this->addColumn\s*\(\s*[\'"]' . preg_quote($columnName, '/') . '[\'"]\s*,\s*new\s+\w+\s*\([^)]*\)\s*\)\s*;\s*\n?/s';
        $newContent = preg_replace($pattern, '', $content);
        
        // Also remove associated foreign key if exists
        $fkPattern = '/\$this->addForeignKey\s*\(\s*[\'"]' . preg_quote($columnName, '/') . '[\'"]\s*[^)]*\)\s*;\s*\n?/s';
        $newContent = preg_replace($fkPattern, '', $newContent);
        
        if ($newContent !== $content) {
            return file_put_contents($filePath, $newContent) !== false;
        }
        
        return false;
    }
}
