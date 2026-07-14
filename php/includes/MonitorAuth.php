<?php

declare(strict_types=1);

final class MonitorAuth
{
    public static function expectedKey(): string
    {
        $monitorKey = Config::get('MONITOR_API_KEY', '');
        if (is_string($monitorKey) && trim($monitorKey) !== '') {
            return trim($monitorKey);
        }

        return trim((string) Config::get('HAMDAST_API_KEY', ''));
    }

    public static function isAuthorized(): bool
    {
        $expected = self::expectedKey();
        if ($expected === '') {
            return false;
        }

        $provided = self::providedKey();

        return $provided !== '' && hash_equals($expected, $provided);
    }

    public static function providedKey(): string
    {
        $header = $_SERVER['HTTP_X_MONITOR_KEY'] ?? $_SERVER['HTTP_X_API_KEY'] ?? '';
        if (is_string($header) && trim($header) !== '') {
            return trim($header);
        }

        $query = $_GET['key'] ?? '';
        if (is_string($query) && trim($query) !== '') {
            return trim($query);
        }

        $bodyKey = '';
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $raw = file_get_contents('php://input');
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && isset($decoded['key']) && is_string($decoded['key'])) {
                    $bodyKey = trim($decoded['key']);
                }
            }
        }

        return $bodyKey;
    }

    public static function requireAuth(): void
    {
        if (self::isAuthorized()) {
            return;
        }

        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
