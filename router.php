<?php
declare(strict_types=1);

/**
 * Local development router for `php -S`.
 * Mirrors key rewrite behavior from .htaccess for extensionless URLs.
 */

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$docRoot = __DIR__;

// Serve existing files/directories directly.
$directPath = realpath($docRoot . $uri);
if ($directPath !== false && str_starts_with($directPath, $docRoot) && (is_file($directPath) || is_dir($directPath))) {
    return false;
}

// / -> index.html
if ($uri === '/') {
    require $docRoot . '/index.html';
    return true;
}

// /page -> /page.html (single segment, same as .htaccess intent)
if (preg_match('#^/[A-Za-z0-9_-]+$#', $uri) === 1) {
    $candidate = $docRoot . $uri . '.html';
    if (is_file($candidate)) {
        require $candidate;
        return true;
    }
}

// Fallback 404 for unknown local routes.
http_response_code(404);
header('Content-Type: text/plain; charset=UTF-8');
echo "Not Found\n";
return true;
