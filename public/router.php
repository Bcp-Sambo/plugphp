<?php
/**
 * public/router.php — router script for PHP's built-in dev server ONLY.
 *
 * The built-in server does not read .htaccess, so this reproduces the
 * front-controller behaviour for local development:
 *   - real files under public/ (assets, uploads, install.php) are served
 *     as-is / executed;
 *   - every other path is handed to index.php for routing.
 *
 * Run (bound to 127.0.0.1 only — never 0.0.0.0):
 *   php -S 127.0.0.1:8000 -t public public/router.php
 *
 * This file is a dev convenience; production uses public/.htaccess instead.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

// Deny dotfiles, mirroring public/.htaccess — never serve a stray .env etc.
foreach (explode('/', $path) as $segment) {
    if ($segment !== '' && $segment[0] === '.') {
        http_response_code(404);
        return true;
    }
}

$file = __DIR__ . $path;

if ($path !== '/' && is_file($file)) {
    return false; // let the built-in server serve/execute the real file
}

require __DIR__ . '/index.php';
