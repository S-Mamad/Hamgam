<?php

declare(strict_types=1);

/**
 * Redirect to monitor dashboard HTML.
 * GET /php/monitor/ or /monitor/
 */

$target = 'index.html';
$query = $_SERVER['QUERY_STRING'] ?? '';
if ($query !== '') {
    $target .= '?' . $query;
}

header('Location: ' . $target, true, 302);
exit;
