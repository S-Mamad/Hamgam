<?php

declare(strict_types=1);

final class Request
{
    /** @var array<string, mixed>|null */
    private static ?array $jsonBodyCache = null;

    private static bool $jsonBodyRead = false;

    public static function accessToken(): string
    {
        $candidates = [
            self::extractBearerToken($_SERVER['HTTP_AUTHORIZATION'] ?? ''),
            self::extractBearerToken($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''),
            self::normalizeToken($_SERVER['HTTP_ACCESS_TOKEN'] ?? ''),
            self::normalizeToken($_SERVER['HTTP_X_ACCESS_TOKEN'] ?? ''),
        ];

        $body = self::jsonBody();
        if (is_array($body) && isset($body['access_token'])) {
            $candidates[] = self::normalizeToken($body['access_token']);
        }

        foreach ($candidates as $token) {
            if ($token !== '' && self::isValidJwtPublic($token)) {
                return $token;
            }
        }

        return '';
    }

    public static function isValidJwtPublic(string $token): bool
    {
        return self::isValidJwt($token);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function jsonBody(): ?array
    {
        if (self::$jsonBodyRead) {
            return self::$jsonBodyCache;
        }

        self::$jsonBodyRead = true;

        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            self::$jsonBodyCache = null;

            return null;
        }

        $decoded = json_decode($raw, true);
        self::$jsonBodyCache = is_array($decoded) ? $decoded : null;

        return self::$jsonBodyCache;
    }

    public static function applyCors(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowed = Config::get('CORS_ORIGINS', '*');

        if ($allowed === '*') {
            header('Access-Control-Allow-Origin: *');
        } elseif ($origin !== '' && self::originIsAllowed($origin, $allowed)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
        }

        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, access_token, Access-Token, X-Access-Token');
        header('Access-Control-Max-Age: 86400');

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }

    private static function extractBearerToken(mixed $header): string
    {
        if (!is_string($header) || trim($header) === '') {
            return '';
        }

        if (preg_match('/^\s*Bearer\s+(.+)$/i', trim($header), $matches)) {
            return self::normalizeToken($matches[1]);
        }

        return '';
    }

    private static function normalizeToken(mixed $token): string
    {
        return is_string($token) ? trim($token) : '';
    }

    private static function originIsAllowed(string $origin, string $allowed): bool
    {
        $list = array_map('trim', explode(',', $allowed));

        return in_array($origin, $list, true);
    }

    private static function isValidJwt(string $token): bool
    {
        if (strlen($token) < 20 || strlen($token) > 4096) {
            return false;
        }

        return (bool) preg_match('/^[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+$/', $token);
    }
}
