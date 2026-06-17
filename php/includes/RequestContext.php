<?php

declare(strict_types=1);

final class RequestContext
{
    private static ?string $requestId = null;

    public static function generateRequestId(): string
    {
        $incoming = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';
        if (is_string($incoming) && preg_match('/^[a-zA-Z0-9_-]{8,64}$/', trim($incoming))) {
            self::$requestId = trim($incoming);
        } else {
            self::$requestId = bin2hex(random_bytes(8));
        }

        return self::$requestId;
    }

    public static function id(): string
    {
        if (self::$requestId === null) {
            self::generateRequestId();
        }

        return self::$requestId;
    }

    public static function log(string $channel, string $message): void
    {
        error_log('[' . $channel . '][req=' . self::id() . '] ' . $message);
    }
}
