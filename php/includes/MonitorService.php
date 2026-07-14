<?php

declare(strict_types=1);

final class MonitorService
{
    /** @var array<string, true> */
    private static array $dedupeKeys = [];

    private static ?float $requestStartedAt = null;

    /** @var array<string, mixed> */
    private static array $requestMeta = [];

    public static function bootRequest(): void
    {
        self::$requestStartedAt = microtime(true);
        self::$requestMeta = [
            'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? 'CLI'),
            'uri' => self::requestUri(),
            'script' => self::scriptName(),
        ];

        register_shutdown_function(static function (): void {
            self::logRequestCompletion();
        });
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function record(array $options): void
    {
        if (!Config::getBool('MONITOR_ENABLED', true)) {
            return;
        }

        $channel = (string) ($options['channel'] ?? 'system');
        $message = (string) ($options['message'] ?? '');
        if ($message === '') {
            return;
        }

        $level = self::normalizeLevel((string) ($options['level'] ?? 'info'));
        $category = self::normalizeCategory((string) ($options['category'] ?? self::inferCategory($channel)));

        $dedupeKey = md5($channel . '|' . $level . '|' . ($options['user_id'] ?? '') . '|' . $message);
        if (isset(self::$dedupeKeys[$dedupeKey])) {
            return;
        }
        self::$dedupeKeys[$dedupeKey] = true;
        if (count(self::$dedupeKeys) > 500) {
            self::$dedupeKeys = array_slice(self::$dedupeKeys, -250, null, true);
        }

        $context = isset($options['context']) && is_array($options['context'])
            ? self::sanitizeContext($options['context'])
            : null;

        $event = [
            'request_id' => (string) ($options['request_id'] ?? RequestContext::id()),
            'channel' => $channel,
            'level' => $level,
            'category' => $category,
            'action' => isset($options['action']) ? (string) $options['action'] : self::inferAction($message),
            'message' => $message,
            'user_id' => isset($options['user_id']) ? GoogleTokensRepository::normalizeUserId((string) $options['user_id']) : self::extractUserIdFromMessage($message),
            'entity_type' => isset($options['entity_type']) ? (string) $options['entity_type'] : self::extractEntityType($message),
            'entity_id' => isset($options['entity_id']) ? (string) $options['entity_id'] : self::extractEntityId($message),
            'context' => $context,
            'duration_ms' => isset($options['duration_ms']) ? (int) $options['duration_ms'] : null,
            'http_status' => isset($options['http_status']) ? (int) $options['http_status'] : null,
            'ip_address' => isset($options['ip_address']) ? (string) $options['ip_address'] : self::clientIp(),
            'source_file' => isset($options['source_file']) ? (string) $options['source_file'] : null,
        ];

        if ($event['user_id'] === '') {
            $event['user_id'] = null;
        }

        MonitorRepository::insert($event);
    }

    public static function log(string $channel, string $message, string $level = 'info', ?array $context = null, ?string $userId = null): void
    {
        self::record([
            'channel' => $channel,
            'message' => $message,
            'level' => $level,
            'context' => $context,
            'user_id' => $userId,
        ]);
    }

    public static function webhook(string $channel, string $action, string $message, ?string $userId = null, ?array $context = null): void
    {
        self::record([
            'channel' => $channel,
            'category' => 'webhook',
            'action' => $action,
            'message' => $message,
            'user_id' => $userId,
            'context' => $context,
        ]);
    }

    public static function api(string $channel, string $action, string $message, ?string $userId = null, ?array $context = null, ?int $httpStatus = null): void
    {
        self::record([
            'channel' => $channel,
            'category' => 'api',
            'action' => $action,
            'message' => $message,
            'user_id' => $userId,
            'context' => $context,
            'http_status' => $httpStatus,
        ]);
    }

    public static function http(string $method, string $url, int $status, int $durationMs, ?array $context = null): void
    {
        $level = $status >= 500 ? 'error' : ($status >= 400 ? 'warning' : 'info');

        self::record([
            'channel' => 'http-client',
            'category' => 'http',
            'action' => strtoupper($method) . ' ' . self::sanitizeUrl($url),
            'message' => strtoupper($method) . ' ' . self::sanitizeUrl($url) . ' status=' . $status . ' duration_ms=' . $durationMs,
            'level' => $level,
            'http_status' => $status,
            'duration_ms' => $durationMs,
            'context' => $context,
        ]);
    }

    public static function cron(string $job, string $message, string $level = 'info', ?array $context = null): void
    {
        self::record([
            'channel' => 'cron/' . $job,
            'category' => 'cron',
            'action' => $job,
            'message' => $message,
            'level' => $level,
            'context' => $context,
        ]);
    }

    public static function auth(string $channel, string $action, string $message, ?string $userId = null, string $level = 'info'): void
    {
        self::record([
            'channel' => $channel,
            'category' => 'auth',
            'action' => $action,
            'message' => $message,
            'user_id' => $userId,
            'level' => $level,
        ]);
    }

    public static function integration(string $provider, string $action, string $message, ?string $userId = null, ?array $context = null): void
    {
        self::record([
            'channel' => 'integrations/' . $provider,
            'category' => 'integration',
            'action' => $action,
            'message' => $message,
            'user_id' => $userId,
            'context' => $context,
        ]);
    }

    public static function exception(Throwable $e): void
    {
        self::record([
            'channel' => 'exception',
            'level' => 'critical',
            'category' => 'system',
            'action' => 'uncaught.exception',
            'message' => get_class($e) . ': ' . $e->getMessage(),
            'context' => [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => self::truncateTrace($e->getTraceAsString()),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function systemOverview(): array
    {
        $checks = [
            'php' => PHP_VERSION,
            'env' => is_file(__DIR__ . '/../.env'),
            'env_file' => is_file(__DIR__ . '/../.env'),
            'monitor_enabled' => Config::getBool('MONITOR_ENABLED', true),
            'db_driver' => Config::get('DB_DRIVER', 'sqlite'),
            'time' => date('c'),
        ];

        try {
            Database::connection()->query('SELECT 1');
            $checks['database'] = 'ok';
        } catch (Throwable $e) {
            $checks['database'] = 'error';
            $checks['database_error'] = $e->getMessage();
        }

        try {
            MonitorRepository::ensureSchema(Database::connection());
            $checks['monitor_schema'] = 'ok';
            $checks['monitor_events_total'] = MonitorRepository::countEvents([]);
        } catch (Throwable $e) {
            $checks['monitor_schema'] = 'error';
            $checks['monitor_schema_error'] = $e->getMessage();
        }

        $logFile = __DIR__ . '/../storage/php-errors.log';
        $checks['error_log_exists'] = is_file($logFile);
        $checks['error_log_size_bytes'] = is_file($logFile) ? (int) filesize($logFile) : 0;
        $checks['error_log_size'] = $checks['error_log_size_bytes'];

        try {
            $stmt = Database::connection()->query(
                'SELECT COUNT(*) AS total,
                        SUM(CASE WHEN google_refresh_token IS NOT NULL AND TRIM(google_refresh_token) != \'\' THEN 1 ELSE 0 END) AS connected
                 FROM google_tokens'
            );
            $row = $stmt !== false ? $stmt->fetch() : false;
            $checks['users_total'] = is_array($row) ? (int) ($row['total'] ?? 0) : 0;
            $checks['users_connected'] = is_array($row) ? (int) ($row['connected'] ?? 0) : 0;
        } catch (Throwable) {
            $checks['users_total'] = 0;
            $checks['users_connected'] = 0;
        }

        $since24h = date('Y-m-d H:i:s', time() - 86400);
        $stats = MonitorRepository::statsOverview($since24h);
        $checks['events_24h'] = (int) ($stats['total'] ?? 0);
        $checks['errors_24h'] = (int) ($stats['errors'] ?? 0);

        $status = 'ok';
        if (($checks['database'] ?? '') !== 'ok') {
            $status = 'critical';
        } elseif (($checks['errors_24h'] ?? 0) > 50) {
            $status = 'degraded';
        }

        return [
            'status' => $status,
            'checks' => $checks,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function usersHealth(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $pdo = Database::connection();

        $stmt = $pdo->query(
            'SELECT paziresh24_user_id, google_account_email, auto_vacation,
                    google_watch_expiration, last_sync_status, updated_at,
                    CASE WHEN google_refresh_token IS NOT NULL AND TRIM(google_refresh_token) != \'\' THEN 1 ELSE 0 END AS connected
             FROM google_tokens
             ORDER BY updated_at DESC
             LIMIT ' . $limit
        );

        $rows = $stmt !== false ? ($stmt->fetchAll() ?: []) : [];
        $nowMs = (int) (microtime(true) * 1000);

        return array_map(static function (array $row) use ($nowMs): array {
            $watchExp = isset($row['google_watch_expiration']) ? (int) $row['google_watch_expiration'] : 0;
            $syncStatus = null;
            if (isset($row['last_sync_status']) && is_string($row['last_sync_status']) && $row['last_sync_status'] !== '') {
                $decoded = json_decode($row['last_sync_status'], true);
                if (is_array($decoded)) {
                    $syncStatus = $decoded;
                }
            }

            $watchState = 'none';
            if ($watchExp > 0) {
                $watchState = $watchExp > $nowMs ? 'active' : 'expired';
            }

            return [
                'user_id' => (string) ($row['paziresh24_user_id'] ?? ''),
                'email' => (string) ($row['google_account_email'] ?? ''),
                'connected' => (int) ($row['connected'] ?? 0) === 1,
                'auto_vacation' => (int) ($row['auto_vacation'] ?? 0) === 1,
                'watch_state' => $watchState,
                'watch_expiration' => $watchExp > 0 ? $watchExp : null,
                'last_sync_status' => $syncStatus,
                'updated_at' => (string) ($row['updated_at'] ?? ''),
            ];
        }, $rows);
    }

    private static function logRequestCompletion(): void
    {
        if (self::$requestStartedAt === null) {
            return;
        }

        $durationMs = (int) round((microtime(true) - self::$requestStartedAt) * 1000);
        $status = http_response_code();
        if (!is_int($status) || $status === 0) {
            $status = 200;
        }

        $uri = self::$requestMeta['uri'] ?? self::requestUri();
        $method = self::$requestMeta['method'] ?? 'GET';
        $script = self::$requestMeta['script'] ?? '';

        if (self::shouldSkipRequestLog($script, $uri)) {
            return;
        }

        $level = $status >= 500 ? 'error' : ($status >= 400 ? 'warning' : 'info');

        self::record([
            'channel' => 'request',
            'category' => 'system',
            'action' => 'http.request',
            'level' => $level,
            'message' => $method . ' ' . $uri . ' status=' . $status . ' duration_ms=' . $durationMs,
            'duration_ms' => $durationMs,
            'http_status' => $status,
            'context' => [
                'script' => $script,
                'method' => $method,
                'uri' => $uri,
            ],
        ]);
    }

    private static function shouldSkipRequestLog(string $script, string $uri): bool
    {
        if (str_contains($script, '/monitor/') || str_contains($uri, '/monitor/')) {
            return true;
        }

        if (str_contains($script, 'health.php')) {
            return true;
        }

        return false;
    }

    private static function requestUri(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if ($uri === '') {
            return (string) ($_SERVER['SCRIPT_NAME'] ?? 'cli');
        }

        return self::truncate($uri, 512);
    }

    private static function scriptName(): string
    {
        return self::truncate((string) ($_SERVER['SCRIPT_NAME'] ?? ''), 255);
    }

    private static function clientIp(): ?string
    {
        $candidates = [
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
            $_SERVER['HTTP_X_REAL_IP'] ?? '',
            $_SERVER['REMOTE_ADDR'] ?? '',
        ];

        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || trim($candidate) === '') {
                continue;
            }
            $ip = trim(explode(',', $candidate)[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return null;
    }

    private static function normalizeLevel(string $level): string
    {
        $level = strtolower(trim($level));
        $allowed = ['debug', 'info', 'warning', 'error', 'critical'];

        return in_array($level, $allowed, true) ? $level : 'info';
    }

    private static function normalizeCategory(string $category): string
    {
        $category = strtolower(trim($category));
        $allowed = ['system', 'webhook', 'api', 'http', 'auth', 'cron', 'integration', 'vacation', 'appointment'];

        return in_array($category, $allowed, true) ? $category : 'system';
    }

    private static function inferCategory(string $channel): string
    {
        $channel = strtolower($channel);

        if (str_contains($channel, 'webhook') || str_contains($channel, 'paziresh24-hamgam') || str_contains($channel, 'google-vacation')) {
            return str_contains($channel, 'hamgam') || str_contains($channel, 'appointment') ? 'appointment' : 'vacation';
        }
        if (str_contains($channel, 'cron')) {
            return 'cron';
        }
        if (str_contains($channel, 'http')) {
            return 'http';
        }
        if (str_contains($channel, 'auth') || str_contains($channel, 'oauth')) {
            return 'auth';
        }
        if (str_contains($channel, 'integrations')) {
            return 'integration';
        }
        if (str_contains($channel, 'hamgam')) {
            return 'api';
        }

        return 'system';
    }

    private static function inferAction(string $message): ?string
    {
        if (preg_match('/\b(event|action|processed|accepted)\=([a-z0-9_.-]+)/i', $message, $m)) {
            return (string) $m[2];
        }

        if (preg_match('/\b(create|update|cancel|delete|sync|connect|disconnect|login|verify|renew|backfill)\b/i', $message, $m)) {
            return strtolower((string) $m[1]);
        }

        return null;
    }

    private static function extractUserIdFromMessage(string $message): ?string
    {
        if (preg_match('/\b(?:doctor|user|user_id|doctor_id)\s*=\s*([0-9]+)/i', $message, $m)) {
            return GoogleTokensRepository::normalizeUserId((string) $m[1]);
        }

        if (preg_match('/\b(?:for|disconnected|connected|opening app for|repairing partial connection for|removing orphan widget for)\s+user\s+([0-9]+)/i', $message, $m)) {
            return GoogleTokensRepository::normalizeUserId((string) $m[1]);
        }

        if (preg_match('/\buser\s+([0-9]+)\b/i', $message, $m)) {
            return GoogleTokensRepository::normalizeUserId((string) $m[1]);
        }

        return null;
    }

    private static function extractEntityType(string $message): ?string
    {
        if (preg_match('/\bbook_id=/i', $message)) {
            return 'book_id';
        }
        if (preg_match('/\bevent_id=/i', $message) || preg_match('/\bgoogle_event/i', $message)) {
            return 'google_event_id';
        }

        return null;
    }

    private static function extractEntityId(string $message): ?string
    {
        if (preg_match('/\bbook_id=([^\s]+)/i', $message, $m)) {
            return (string) $m[1];
        }
        if (preg_match('/\bevent_id=([^\s]+)/i', $message, $m)) {
            return (string) $m[1];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function sanitizeContext(array $context): array
    {
        $sanitized = [];
        $secretPattern = '/token|secret|password|authorization|refresh|access/i';

        foreach ($context as $key => $value) {
            $keyStr = (string) $key;
            if (preg_match($secretPattern, $keyStr)) {
                $sanitized[$keyStr] = '[REDACTED]';
                continue;
            }

            if (is_string($value)) {
                if (preg_match($secretPattern, $value) || strlen($value) > 500) {
                    $sanitized[$keyStr] = '[REDACTED]';
                } else {
                    $sanitized[$keyStr] = $value;
                }
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $sanitized[$keyStr] = $value;
                continue;
            }

            if (is_array($value)) {
                $sanitized[$keyStr] = self::sanitizeContext($value);
            }
        }

        return $sanitized;
    }

    private static function sanitizeUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return self::truncate($url, 256);
        }

        $host = (string) ($parts['host'] ?? '');
        $path = (string) ($parts['path'] ?? '');

        return self::truncate($host . $path, 256);
    }

    private static function truncate(string $value, int $max): string
    {
        if (strlen($value) <= $max) {
            return $value;
        }

        return substr($value, 0, $max - 3) . '...';
    }

    private static function truncateTrace(string $trace): string
    {
        return self::truncate($trace, 4000);
    }
}
