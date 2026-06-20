<?php

declare(strict_types=1);

$raw = file_get_contents(__DIR__ . '/login-chunk-fresh.js');
$pos = 0;
while (($pos = strpos($raw, 'setTokenAndUserName', $pos)) !== false) {
    echo substr($raw, max(0, $pos - 200), 600) . "\n\n===\n\n";
    $pos += 20;
}
