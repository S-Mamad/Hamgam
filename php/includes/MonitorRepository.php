<?php

declare(strict_types=1);

final class MonitorRepository
{
    private const MAX_MESSAGE_LENGTH = 8000;
    private const MAX_CONTEXT_LENGTH = 16000;
    private const RETENTION_DAYS = 45;
    private const MAX_ROWS = 200000;

    /** @var bool */
    private static bool $schemaReady = false;

    public static function ensureSchema(PDO $pdo): void
    {
        if (self::$schemaReady) {
            return;
        }

        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'mysql') {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS monitor_events (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    request_id VARCHAR(64) NOT NULL DEFAULT \'\',
                    channel VARCHAR(64) NOT NULL DEFAULT \'system\',
                    level VARCHAR(16) NOT NULL DEFAULT \'info\',
                    category VARCHAR(32) NOT NULL DEFAULT \'system\',
                    action VARCHAR(128) NULL,
                    message TEXT NOT NULL,
                    user_id VARCHAR(64) NULL,
                    entity_type VARCHAR(32) NULL,
                    entity_id VARCHAR(256) NULL,
                    context_json TEXT NULL,
                    duration_ms INT NULL,
                    http_status INT NULL,
                    ip_address VARCHAR(45) NULL,
                    source_file VARCHAR(255) NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_monitor_events_created (created_at),
                    INDEX idx_monitor_events_channel (channel, created_at),
                    INDEX idx_monitor_events_level (level, created_at),
                    INDEX idx_monitor_events_user (user_id, created_at),
                    INDEX idx_monitor_events_request (request_id),
                    INDEX idx_monitor_events_category (category, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS monitor_daily_rollups (
                    rollup_date DATE NOT NULL,
                    channel VARCHAR(64) NOT NULL DEFAULT \'_all\',
                    level VARCHAR(16) NOT NULL DEFAULT \'_all\',
                    event_count INT NOT NULL DEFAULT 0,
                    error_count INT NOT NULL DEFAULT 0,
                    avg_duration_ms DOUBLE NULL,
                    PRIMARY KEY (rollup_date, channel, level)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } else {
            $schemaFile = __DIR__ . '/../sql/monitor_events.sql';
            if (is_file($schemaFile)) {
                $sql = file_get_contents($schemaFile);
                if (is_string($sql) && $sql !== '') {
                    $pdo->exec($sql);
                }
            }
        }

        self::$schemaReady = true;
    }

    /**
     * @param array<string, mixed> $event
     */
    public static function insert(array $event): ?int
    {
        try {
            $pdo = Database::connection();
            self::ensureSchema($pdo);

            $message = self::truncate((string) ($event['message'] ?? ''), self::MAX_MESSAGE_LENGTH);
            if ($message === '') {
                return null;
            }

            $contextJson = null;
            if (isset($event['context']) && is_array($event['context']) && $event['context'] !== []) {
                $encoded = json_encode($event['context'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (is_string($encoded)) {
                    $contextJson = self::truncate($encoded, self::MAX_CONTEXT_LENGTH);
                }
            }

            $stmt = $pdo->prepare(
                'INSERT INTO monitor_events (
                    request_id, channel, level, category, action, message,
                    user_id, entity_type, entity_id, context_json,
                    duration_ms, http_status, ip_address, source_file, created_at
                ) VALUES (
                    :request_id, :channel, :level, :category, :action, :message,
                    :user_id, :entity_type, :entity_id, :context_json,
                    :duration_ms, :http_status, :ip_address, :source_file, :created_at
                )'
            );

            $createdAt = $event['created_at'] ?? date('Y-m-d H:i:s');

            $stmt->execute([
                'request_id' => self::truncate((string) ($event['request_id'] ?? ''), 64),
                'channel' => self::truncate((string) ($event['channel'] ?? 'system'), 64),
                'level' => self::truncate((string) ($event['level'] ?? 'info'), 16),
                'category' => self::truncate((string) ($event['category'] ?? 'system'), 32),
                'action' => self::nullableString($event['action'] ?? null, 128),
                'message' => $message,
                'user_id' => self::nullableString($event['user_id'] ?? null, 64),
                'entity_type' => self::nullableString($event['entity_type'] ?? null, 32),
                'entity_id' => self::nullableString($event['entity_id'] ?? null, 256),
                'context_json' => $contextJson,
                'duration_ms' => self::nullableInt($event['duration_ms'] ?? null),
                'http_status' => self::nullableInt($event['http_status'] ?? null),
                'ip_address' => self::nullableString($event['ip_address'] ?? null, 45),
                'source_file' => self::nullableString($event['source_file'] ?? null, 255),
                'created_at' => $createdAt,
            ]);

            $id = (int) $pdo->lastInsertId();
            self::maybeCleanup($pdo);

            return $id > 0 ? $id : null;
        } catch (Throwable $e) {
            error_log('[MonitorRepository] insert failed: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listEvents(array $filters, int $limit = 100, int $offset = 0): array
    {
        $pdo = Database::connection();
        self::ensureSchema($pdo);

        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);

        $built = self::buildWhereClause($filters);
        $whereSql = $built['where'];
        $params = $built['params'];

        $sql = 'SELECT id, request_id, channel, level, category, action, message,
                       user_id, entity_type, entity_id, context_json,
                       duration_ms, http_status, ip_address, source_file, created_at
                FROM monitor_events
                WHERE 1=1' . $whereSql . '
                ORDER BY id DESC
                LIMIT ' . $limit . ' OFFSET ' . $offset;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll() ?: [];

        return array_map(static function (array $row): array {
            $context = null;
            if (isset($row['context_json']) && is_string($row['context_json']) && $row['context_json'] !== '') {
                $decoded = json_decode($row['context_json'], true);
                if (is_array($decoded)) {
                    $context = $decoded;
                }
            }
            unset($row['context_json']);
            $row['context'] = $context;

            return $row;
        }, $rows);
    }

    public static function countEvents(array $filters): int
    {
        $pdo = Database::connection();
        self::ensureSchema($pdo);

        $built = self::buildWhereClause($filters);
        $whereSql = $built['where'];
        $params = $built['params'];

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM monitor_events WHERE 1=1' . $whereSql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $pdo = Database::connection();
        self::ensureSchema($pdo);

        $stmt = $pdo->prepare(
            'SELECT id, request_id, channel, level, category, action, message,
                    user_id, entity_type, entity_id, context_json,
                    duration_ms, http_status, ip_address, source_file, created_at
             FROM monitor_events WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            return null;
        }

        $context = null;
        if (isset($row['context_json']) && is_string($row['context_json']) && $row['context_json'] !== '') {
            $decoded = json_decode($row['context_json'], true);
            if (is_array($decoded)) {
                $context = $decoded;
            }
        }
        unset($row['context_json']);
        $row['context'] = $context;

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    public static function statsOverview(string $since): array
    {
        $pdo = Database::connection();
        self::ensureSchema($pdo);

        $stmt = $pdo->prepare(
            'SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN level IN (\'error\', \'critical\') THEN 1 ELSE 0 END) AS errors,
                SUM(CASE WHEN level = \'warning\' THEN 1 ELSE 0 END) AS warnings,
                SUM(CASE WHEN category = \'webhook\' THEN 1 ELSE 0 END) AS webhooks,
                SUM(CASE WHEN category = \'http\' THEN 1 ELSE 0 END) AS http_calls,
                SUM(CASE WHEN category = \'auth\' THEN 1 ELSE 0 END) AS auth_events,
                SUM(CASE WHEN category = \'cron\' THEN 1 ELSE 0 END) AS cron_events,
                AVG(duration_ms) AS avg_duration_ms
             FROM monitor_events
             WHERE created_at >= :since'
        );
        $stmt->execute(['since' => $since]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function statsByChannel(string $since, int $limit = 20): array
    {
        $pdo = Database::connection();
        self::ensureSchema($pdo);
        $limit = max(1, min(50, $limit));

        $stmt = $pdo->prepare(
            'SELECT channel,
                    COUNT(*) AS total,
                    SUM(CASE WHEN level IN (\'error\', \'critical\') THEN 1 ELSE 0 END) AS errors
             FROM monitor_events
             WHERE created_at >= :since
             GROUP BY channel
             ORDER BY total DESC
             LIMIT ' . $limit
        );
        $stmt->execute(['since' => $since]);

        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function statsByHour(string $since): array
    {
        $pdo = Database::connection();
        self::ensureSchema($pdo);
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'mysql') {
            $stmt = $pdo->prepare(
                'SELECT DATE_FORMAT(created_at, \'%Y-%m-%d %H:00:00\') AS hour_bucket,
                        COUNT(*) AS total,
                        SUM(CASE WHEN level IN (\'error\', \'critical\') THEN 1 ELSE 0 END) AS errors
                 FROM monitor_events
                 WHERE created_at >= :since
                 GROUP BY hour_bucket
                 ORDER BY hour_bucket ASC'
            );
        } else {
            $stmt = $pdo->prepare(
                "SELECT strftime('%Y-%m-%d %H:00:00', created_at) AS hour_bucket,
                        COUNT(*) AS total,
                        SUM(CASE WHEN level IN ('error', 'critical') THEN 1 ELSE 0 END) AS errors
                 FROM monitor_events
                 WHERE created_at >= :since
                 GROUP BY hour_bucket
                 ORDER BY hour_bucket ASC"
            );
        }

        $stmt->execute(['since' => $since]);

        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function recentErrors(int $limit = 20): array
    {
        return self::listEvents(
            ['levels' => ['error', 'critical']],
            max(1, min(100, $limit)),
            0
        );
    }

    /**
     * @return array<int, string>
     */
    public static function distinctChannels(): array
    {
        $pdo = Database::connection();
        self::ensureSchema($pdo);

        $stmt = $pdo->query(
            'SELECT DISTINCT channel FROM monitor_events ORDER BY channel ASC LIMIT 100'
        );
        if ($stmt === false) {
            return [];
        }

        $channels = [];
        while ($row = $stmt->fetch()) {
            $channel = (string) ($row['channel'] ?? '');
            if ($channel !== '') {
                $channels[] = $channel;
            }
        }

        return $channels;
    }

    /**
     * @return array{where:string, params:array<string, mixed>}
     */
    private static function buildWhereClause(array $filters): array
    {
        $where = '';
        $params = [];

        if (isset($filters['since']) && is_string($filters['since']) && $filters['since'] !== '') {
            $where .= ' AND created_at >= :since';
            $params['since'] = $filters['since'];
        }

        if (isset($filters['until']) && is_string($filters['until']) && $filters['until'] !== '') {
            $where .= ' AND created_at <= :until';
            $params['until'] = $filters['until'];
        }

        if (isset($filters['channel']) && is_string($filters['channel']) && $filters['channel'] !== '') {
            $where .= ' AND channel = :channel';
            $params['channel'] = $filters['channel'];
        }

        if (isset($filters['level']) && is_string($filters['level']) && $filters['level'] !== '') {
            $where .= ' AND level = :level';
            $params['level'] = $filters['level'];
        }

        if (isset($filters['levels']) && is_array($filters['levels']) && $filters['levels'] !== []) {
            $levels = array_values(array_filter($filters['levels'], static fn ($v) => is_string($v) && $v !== ''));
            if ($levels !== []) {
                $placeholders = [];
                foreach ($levels as $i => $level) {
                    $key = 'level_' . $i;
                    $placeholders[] = ':' . $key;
                    $params[$key] = $level;
                }
                $where .= ' AND level IN (' . implode(', ', $placeholders) . ')';
            }
        }

        if (isset($filters['category']) && is_string($filters['category']) && $filters['category'] !== '') {
            $where .= ' AND category = :category';
            $params['category'] = $filters['category'];
        }

        if (isset($filters['user_id']) && is_string($filters['user_id']) && $filters['user_id'] !== '') {
            $where .= ' AND user_id = :user_id';
            $params['user_id'] = $filters['user_id'];
        }

        if (isset($filters['request_id']) && is_string($filters['request_id']) && $filters['request_id'] !== '') {
            $where .= ' AND request_id = :request_id';
            $params['request_id'] = $filters['request_id'];
        }

        if (isset($filters['search']) && is_string($filters['search']) && trim($filters['search']) !== '') {
            $where .= ' AND (message LIKE :search OR action LIKE :search OR entity_id LIKE :search)';
            $params['search'] = '%' . trim($filters['search']) . '%';
        }

        if (isset($filters['min_id']) && is_int($filters['min_id']) && $filters['min_id'] > 0) {
            $where .= ' AND id > :min_id';
            $params['min_id'] = $filters['min_id'];
        }

        if (isset($filters['max_id']) && is_int($filters['max_id']) && $filters['max_id'] > 0) {
            $where .= ' AND id < :max_id';
            $params['max_id'] = $filters['max_id'];
        }

        return ['where' => $where, 'params' => $params];
    }

    private static function maybeCleanup(PDO $pdo): void
    {
        static $lastCleanupAt = 0;
        $now = time();
        if ($now - $lastCleanupAt < 300) {
            return;
        }
        $lastCleanupAt = $now;

        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'mysql') {
                $pdo->exec(
                    'DELETE FROM monitor_events
                     WHERE created_at < DATE_SUB(NOW(), INTERVAL ' . self::RETENTION_DAYS . ' DAY)'
                );
            } else {
                $pdo->exec(
                    "DELETE FROM monitor_events
                     WHERE created_at < datetime('now', '-" . self::RETENTION_DAYS . " days')"
                );
            }

            $count = (int) $pdo->query('SELECT COUNT(*) FROM monitor_events')->fetchColumn();
            if ($count > self::MAX_ROWS) {
                $excess = $count - self::MAX_ROWS;
                if ($driver === 'mysql') {
                    $pdo->exec(
                        'DELETE FROM monitor_events ORDER BY id ASC LIMIT ' . $excess
                    );
                } else {
                    $pdo->exec(
                        'DELETE FROM monitor_events WHERE id IN (
                            SELECT id FROM monitor_events ORDER BY id ASC LIMIT ' . $excess . '
                        )'
                    );
                }
            }
        } catch (Throwable $e) {
            error_log('[MonitorRepository] cleanup failed: ' . $e->getMessage());
        }
    }

    private static function truncate(string $value, int $max): string
    {
        if (strlen($value) <= $max) {
            return $value;
        }

        return substr($value, 0, $max - 3) . '...';
    }

    private static function nullableString(mixed $value, int $max): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        return self::truncate($trimmed, $max);
    }

    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }
}
