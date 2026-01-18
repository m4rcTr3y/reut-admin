<?php
declare(strict_types=1);

namespace Reut\Admin\Services;

/**
 * Error Sanitizer Service
 * Sanitizes error messages to prevent information leakage
 */
class ErrorSanitizer
{
    /**
     * Get a safe error message for users
     * Logs detailed error internally
     */
    public static function sanitize(\Throwable $e, string $context = 'operation'): string
    {
        // Log the full error details for debugging (server-side only)
        error_log(sprintf(
            '[Admin Error] %s: %s in %s:%d - %s',
            $context,
            get_class($e),
            $e->getFile(),
            $e->getLine(),
            $e->getMessage()
        ));

        // Return generic user-friendly message
        $message = $e->getMessage();
        
        // Remove file paths
        $message = preg_replace('/\/[^\s]+\.(php|env|log|txt|json)/i', '[file]', $message);
        
        // Remove absolute paths
        $message = preg_replace('/\/[a-zA-Z0-9_\/\-\.]+/i', '[path]', $message);
        
        // Remove database table/column names that might leak schema
        $message = preg_replace('/\b(table|column|database)\s+[\'"]?([a-zA-Z_][a-zA-Z0-9_]*)[\'"]?/i', '$1 [name]', $message);
        
        // Remove SQL error details
        if (preg_match('/SQLSTATE|PDOException|SQL syntax/i', $message)) {
            return 'A database error occurred. Please try again or contact support.';
        }
        
        // Remove class names that might leak structure
        $message = preg_replace('/\b(Reut\\\\.*?|class\s+[A-Z][a-zA-Z0-9_]+)/i', '[class]', $message);
        
        // Generic fallback for any remaining technical details
        if (strlen($message) > 200 || preg_match('/\b(error|exception|failed|undefined)\s+[A-Z]/i', $message)) {
            return 'An error occurred while processing your request. Please try again.';
        }
        
        return $message ?: 'An unexpected error occurred. Please try again.';
    }

    /**
     * Get a generic error message based on error type
     */
    public static function getGenericMessage(string $errorType = 'general'): string
    {
        $messages = [
            'general' => 'An error occurred while processing your request.',
            'not_found' => 'The requested resource was not found.',
            'unauthorized' => 'You do not have permission to perform this action.',
            'validation' => 'The provided data is invalid.',
            'database' => 'A database error occurred. Please try again.',
            'file' => 'A file operation error occurred.',
            'network' => 'A network error occurred. Please check your connection.',
            'timeout' => 'The operation timed out. Please try again.',
        ];

        return $messages[$errorType] ?? $messages['general'];
    }

    /**
     * Check if error message contains sensitive information
     */
    public static function containsSensitiveInfo(string $message): bool
    {
        $sensitivePatterns = [
            '/\.env/',
            '/config\.php/',
            '/\/var\/www/',
            '/\/home\/[^\/]+/',
            '/password|secret|key|token/i',
            '/SQLSTATE|PDOException/',
            '/file_get_contents|file_put_contents/',
        ];

        foreach ($sensitivePatterns as $pattern) {
            if (preg_match($pattern, $message)) {
                return true;
            }
        }

        return false;
    }
}



