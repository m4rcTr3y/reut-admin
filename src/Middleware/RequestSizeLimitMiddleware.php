<?php
declare(strict_types=1);

namespace Reut\Admin\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

/**
 * Request Size Limit Middleware
 * Limits the size of request bodies to prevent DoS attacks
 */
class RequestSizeLimitMiddleware
{
    private int $maxSizeBytes;
    private bool $enabled;

    public function __construct(array $config)
    {
        $this->enabled = filter_var($config['ADMIN_REQUEST_SIZE_LIMIT_ENABLED'] ?? 'true', FILTER_VALIDATE_BOOLEAN);
        
        // Default: 10MB (10 * 1024 * 1024)
        $maxSizeMB = (int)($config['ADMIN_REQUEST_SIZE_LIMIT_MB'] ?? 10);
        $this->maxSizeBytes = $maxSizeMB * 1024 * 1024;
    }

    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        if (!$this->enabled) {
            return $handler->handle($request);
        }

        // Check Content-Length header
        $contentLength = $request->getHeaderLine('Content-Length');
        if (!empty($contentLength)) {
            $size = (int)$contentLength;
            if ($size > $this->maxSizeBytes) {
                return $this->sizeLimitExceededResponse($this->maxSizeBytes);
            }
        }

        // For methods that might have bodies, check actual body size
        $method = $request->getMethod();
        if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $body = $request->getBody();
            $bodySize = $body->getSize();
            
            // If size is known and exceeds limit
            if ($bodySize !== null && $bodySize > $this->maxSizeBytes) {
                return $this->sizeLimitExceededResponse($this->maxSizeBytes);
            }
            
            // For streaming bodies, we need to read and check
            // But we'll limit this to prevent memory issues
            if ($bodySize === null) {
                // Read first chunk to check
                $body->rewind();
                $chunk = $body->read(min(1024, $this->maxSizeBytes + 1));
                if (strlen($chunk) > $this->maxSizeBytes) {
                    return $this->sizeLimitExceededResponse($this->maxSizeBytes);
                }
                $body->rewind(); // Reset for handler
            }
        }

        return $handler->handle($request);
    }

    private function sizeLimitExceededResponse(int $maxBytes): Response
    {
        $maxMB = round($maxBytes / (1024 * 1024), 2);
        $response = new SlimResponse();
        $response->getBody()->write(json_encode([
            'error' => 'Request too large',
            'message' => "Request body exceeds maximum size of {$maxMB}MB",
            'max_size_mb' => $maxMB
        ], JSON_UNESCAPED_SLASHES));
        return $response
            ->withStatus(413) // 413 Payload Too Large
            ->withHeader('Content-Type', 'application/json');
    }
}


