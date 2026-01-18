<?php
declare(strict_types=1);

namespace Reut\Admin\Services;

use Reut\Admin\Models\FunctionModel;
use Reut\Support\ProjectPath;

/**
 * Function Service
 * Handles function file operations and validation
 */
class FunctionService
{
    private array $config;
    private string $functionsDir;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->functionsDir = ProjectPath::root() . '/functions';
        
        // Ensure functions directory exists
        if (!is_dir($this->functionsDir)) {
            mkdir($this->functionsDir, 0755, true);
        }
    }

    /**
     * Get functions directory path
     */
    public function getFunctionsDir(): string
    {
        return $this->functionsDir;
    }

    /**
     * Validate function name
     */
    public function validateFunctionName(string $name): bool
    {
        // Alphanumeric, underscore, and hyphen only
        return preg_match('/^[a-zA-Z0-9_-]+$/', $name) === 1;
    }

    /**
     * Get function file path
     */
    public function getFunctionFilePath(string $name): string
    {
        if (!$this->validateFunctionName($name)) {
            throw new \InvalidArgumentException("Invalid function name: {$name}");
        }
        
        return $this->functionsDir . '/' . $name . '.php';
    }

    /**
     * Create function file
     */
    public function createFunctionFile(string $name, string $code): bool
    {
        $filePath = $this->getFunctionFilePath($name);
        
        // Validate PHP syntax
        $this->validatePhpSyntax($code);
        
        // Write file
        return file_put_contents($filePath, $code) !== false;
    }

    /**
     * Read function file
     */
    public function readFunctionFile(string $name): ?string
    {
        $filePath = $this->getFunctionFilePath($name);
        
        if (!file_exists($filePath)) {
            return null;
        }
        
        return file_get_contents($filePath);
    }

    /**
     * Update function file
     */
    public function updateFunctionFile(string $name, string $code): bool
    {
        return $this->createFunctionFile($name, $code);
    }

    /**
     * Delete function file
     */
    public function deleteFunctionFile(string $name): bool
    {
        $filePath = $this->getFunctionFilePath($name);
        
        if (!file_exists($filePath)) {
            return true; // Already deleted
        }
        
        return unlink($filePath);
    }

    /**
     * Check if function file exists
     */
    public function functionFileExists(string $name): bool
    {
        $filePath = $this->getFunctionFilePath($name);
        return file_exists($filePath);
    }

    /**
     * Validate PHP syntax
     */
    public function validatePhpSyntax(string $code): void
    {
        // Check if code contains return statement with function
        if (strpos($code, 'return function') === false && strpos($code, 'return function(') === false) {
            throw new \InvalidArgumentException("Function must return a callable. Expected: return function(\$request, \$params) { ... }");
        }

        // Try to parse PHP syntax
        $tempFile = tempnam(sys_get_temp_dir(), 'reut_function_');
        file_put_contents($tempFile, $code);
        
        // Use php -l to check syntax
        $output = [];
        $returnCode = 0;
        exec("php -l {$tempFile} 2>&1", $output, $returnCode);
        
        unlink($tempFile);
        
        if ($returnCode !== 0) {
            $error = implode("\n", $output);
            throw new \InvalidArgumentException("PHP syntax error: " . $error);
        }
    }

    /**
     * Get all function files
     */
    public function getAllFunctionFiles(): array
    {
        $files = [];
        
        if (!is_dir($this->functionsDir)) {
            return $files;
        }
        
        $dirFiles = scandir($this->functionsDir);
        
        foreach ($dirFiles as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            
            if (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                $name = pathinfo($file, PATHINFO_FILENAME);
                if ($this->validateFunctionName($name)) {
                    $files[] = $name;
                }
            }
        }
        
        return $files;
    }
}

