<?php

declare(strict_types=1);

/**
 * Verifies JWT user-id extraction and instant redirect HTML helper behavior.
 *
 * Usage: php php/tools/test-oauth-response-speed.php
 */

require_once __DIR__ . '/../includes/bootstrap.php';

$failed = 0;

function assertTrue(bool $condition, string $message): void
{
    global $failed;

    if ($condition) {
        echo 'OK   ' . $message . PHP_EOL;
        return;
    }

    echo 'FAIL ' . $message . PHP_EOL;
    $failed++;
}

$jwt = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzY29wZSI6WyJwcm92aWRlci5wcm9maWxlLnJlYWQiXSwiaXNzIjoiaGFtZGFzdCIsInN1YiI6IjIzNDg5NDQyIiwiYXVkIjoiZnppeGpheTRpNThkZGFjIiwiaWF0IjoxNzgxNDM5MjM2fQ.signature';
$userId = Paziresh24Api::extractUserIdFromJwt($jwt);
assertTrue($userId === '23489442', 'extractUserIdFromJwt reads sub claim');

$invalid = Paziresh24Api::extractUserIdFromJwt('not-a-jwt');
assertTrue($invalid === null, 'extractUserIdFromJwt rejects invalid token');

$ref = new ReflectionClass(Response::class);
$method = $ref->getMethod('sendInstantRedirectHtml');
$method->setAccessible(true);

ob_start();
try {
    $method->invoke(null, 'https://example.com/settings/?oauth=success');
} catch (Throwable $e) {
    ob_end_clean();
    assertTrue(false, 'sendInstantRedirectHtml should not throw: ' . $e->getMessage());
    exit($failed > 0 ? 1 : 0);
}
$html = (string) ob_get_clean();

assertTrue(str_contains($html, 'location.replace'), 'instant redirect HTML includes JS redirect');
assertTrue(str_contains($html, 'oauth=success'), 'instant redirect HTML includes target URL');

exit($failed > 0 ? 1 : 0);
