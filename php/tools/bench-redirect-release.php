<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$state = json_encode(['return_to' => 'settings'], JSON_UNESCAPED_SLASHES);
$url = rtrim(Config::require('REDIRECT_SETTINGS'), '/') . '/?oauth=success';

Response::redirectThenContinue(
    $url,
    static function (): void {
        usleep(8_000_000);
    }
);
