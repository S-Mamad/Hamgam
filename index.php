<?php

declare(strict_types=1);

/**
 * Host-based entry: zamanak24.ir → English landing, hamgam.zamanak24.ir → app.
 */

$host = preg_replace('/^www\./', '', strtolower($_SERVER['HTTP_HOST'] ?? ''));
$landingHosts = ['zamanak24.ir'];
$isLandingHost = in_array($host, $landingHosts, true);

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$uri = '/' . ltrim($uri, '/');

if ($isLandingHost) {
    $landingRoot = __DIR__ . '/public-landing';
    $landingRootReal = realpath($landingRoot);

    if ($landingRootReal === false) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Landing files are missing on the server.';
        exit;
    }

    $relative = $uri;
    if ($relative === '/' || $relative === '/index.html') {
        $relative = '/index.html';
    }

    $allowed = [
        '/index.html' => 'text/html; charset=UTF-8',
        '/landing.css' => 'text/css; charset=UTF-8',
        '/privacy-policy.html' => 'text/html; charset=UTF-8',
        '/terms-of-service.html' => 'text/html; charset=UTF-8',
        '/legal.css' => 'text/css; charset=UTF-8',
        '/legal-base.css' => 'text/css; charset=UTF-8',
    ];

    if (!isset($allowed[$relative])) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Not found';
        exit;
    }

    $target = realpath($landingRootReal . $relative);

    if (
        $target !== false
        && str_starts_with($target, $landingRootReal . DIRECTORY_SEPARATOR)
        && is_file($target)
    ) {
        header('Content-Type: ' . $allowed[$relative]);
        readfile($target);
        exit;
    }

    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Not found';
    exit;
}

$appIndex = __DIR__ . '/index.html';
if (!is_file($appIndex)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Application entry is missing.';
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
readfile($appIndex);
