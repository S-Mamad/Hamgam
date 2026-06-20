<?php

declare(strict_types=1);

$raw = file_get_contents(__DIR__ . '/login-chunk.js');
$pos = 97645;
echo substr($raw, $pos, 3500);
