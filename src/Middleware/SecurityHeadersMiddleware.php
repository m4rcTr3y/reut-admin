<?php
declare(strict_types=1);

namespace Reut\Admin\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * Security Headers Middleware
 * Adds security headers to all admin responses
 */
class SecurityHeadersMiddleware
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        $response = $handler->handle($request);

        // Content Security Policy
        // Allow self, inline scripts/styles for React, and common CDNs
        $csp = "default-src 'self'; " .
               "script-src 'self' 'unsafe-inline' 'unsafe-eval'; " .
               "style-src 'self' 'unsafe-inline'; " .
               "img-src 'self' data: https:; " .
               "font-src 'self' data:; " .
               "connect-src 'self'; " .
               "frame-ancestors 'none';";
        
        $response = $response->withHeader('Content-Security-Policy', $csp);
        
        // Prevent clickjacking
        $response = $response->withHeader('X-Frame-Options', 'DENY');
        
        // Prevent MIME type sniffing
        $response = $response->withHeader('X-Content-Type-Options', 'nosniff');
        
        // Enable XSS protection (legacy browsers)
        $response = $response->withHeader('X-XSS-Protection', '1; mode=block');
        
        // Referrer policy
        $response = $response->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        
        // HSTS (only if HTTPS and enabled via config)
        $httpsOnly = filter_var($this->config['ADMIN_HTTPS_ONLY'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
        $scheme = $request->getUri()->getScheme();
        if ($httpsOnly && $scheme === 'https') {
            $response = $response->withHeader(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains; preload'
            );
        }
        
        // Permissions Policy (formerly Feature-Policy)
        $response = $response->withHeader(
            'Permissions-Policy',
            'geolocation=(), microphone=(), camera=(), payment=(), usb=(), bluetooth=(), magnetometer=(), gyroscope=(), accelerometer=()'
        );
        
        // Cross-Origin policies
        $response = $response->withHeader('Cross-Origin-Embedder-Policy', 'require-corp');
        $response = $response->withHeader('Cross-Origin-Opener-Policy', 'same-origin');
        $response = $response->withHeader('Cross-Origin-Resource-Policy', 'same-origin');
        
        // Additional security headers
        $response = $response->withHeader('X-Permitted-Cross-Domain-Policies', 'none');
        $response = $response->withHeader('X-Download-Options', 'noopen'); // IE8+ only
        
        // Remove server information
        $response = $response->withoutHeader('X-Powered-By');
        $response = $response->withoutHeader('Server');

        return $response;
    }
}

