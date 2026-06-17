<?php

declare(strict_types=1);

/**
 * GET /php/hamgam/health.php — تست سریع بعد از آپلود (JSON، بدون auth)
 */

require_once __DIR__ . '/../includes/bootstrap.php';

Request::applyCors();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    Response::jsonError('Method not allowed', 405);
}

$checks = [
    'php' => PHP_VERSION,
    'env' => is_file(__DIR__ . '/../.env'),
    'ssl_verify' => Config::getBool('HTTP_SSL_VERIFY', true),
    'db_driver' => Config::get('DB_DRIVER', 'sqlite'),
];

try {
    Database::connection()->query('SELECT 1');
    $checks['database'] = 'ok';
} catch (Throwable $e) {
    $checks['database'] = 'error';
    RequestContext::log('hamgam/health', 'database: ' . $e->getMessage());
}

Response::json([
    'status' => ($checks['env'] && $checks['database'] === 'ok') ? 'ok' : 'degraded',
    'checks' => $checks,
]);
