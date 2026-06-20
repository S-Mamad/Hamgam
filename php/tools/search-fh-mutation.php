<?php

declare(strict_types=1);

$raw = file_get_contents(__DIR__ . '/login-chunk.js');
if ($raw === false) {
    exit(1);
}

$pos = strpos($raw, 'c.FH');
while ($pos !== false) {
    echo substr($raw, max(0, $pos - 200), 800) . "\n=====\n";
    $pos = strpos($raw, 'c.FH', $pos + 4);
    if ($pos > 250000) {
        break;
    }
}
