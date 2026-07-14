<?php

declare(strict_types=1);

/**
 * Copy all Hamgam tables from MySQL or SQLite into PostgreSQL.
 *
 * Usage:
 *   1. Set SOURCE_* and TARGET_* env vars (see php/.env.example postgres section)
 *   2. php php/tools/migrate-to-postgres.php
 *
 * Or one-off:
 *   SOURCE_DB_DRIVER=mysql TARGET_DB_DRIVER=pgsql php php/tools/migrate-to-postgres.php
 */

require_once __DIR__ . '/../includes/bootstrap.php';

final class PostgresMigrator
{
    private const TABLES_IN_ORDER = [
        'google_tokens',
        'doctor_external_connections',
        'google_event_vacations',
        'google_calendar_bookings',
        'import_future_vacations_doctor_lock',
        'import_future_vacations_backfill_slots',
        'drdr_pending_otp',
        'monitor_events',
        'monitor_daily_rollups',
    ];

    private const PATIENT_COLUMN_MAP = [
        'Patient_name' => 'patient_name',
        'Patient_date_time' => 'patient_date_time',
        'Patient_national' => 'patient_national',
        'Patient_phone' => 'patient_phone',
        'Patient_center' => 'patient_center',
    ];

    public static function run(): int
    {
        $source = self::connect('SOURCE');
        $target = self::connect('TARGET');

        fwrite(STDOUT, "Connected: source=" . $source->getAttribute(PDO::ATTR_DRIVER_NAME) . PHP_EOL);
        fwrite(STDOUT, "Connected: target=" . $target->getAttribute(PDO::ATTR_DRIVER_NAME) . PHP_EOL);

        if ($target->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            fwrite(STDERR, "TARGET must be PostgreSQL (DB_DRIVER=pgsql)\n");
            return 1;
        }

        self::ensureTargetSchema($target);

        $target->beginTransaction();
        try {
            foreach (self::TABLES_IN_ORDER as $table) {
                $count = self::copyTable($source, $target, $table);
                fwrite(STDOUT, sprintf("Copied %s: %d rows\n", $table, $count));
            }
            self::resetSequences($target);
            $target->commit();
        } catch (Throwable $e) {
            $target->rollBack();
            fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . PHP_EOL);
            return 1;
        }

        fwrite(STDOUT, "Migration complete.\n");
        return 0;
    }

    private static function connect(string $prefix): PDO
    {
        $driver = getenv($prefix . '_DB_DRIVER');
        if ($driver === false || $driver === '') {
            $driver = $prefix === 'TARGET'
                ? 'pgsql'
                : (string) Config::get('DB_DRIVER', 'sqlite');
        }

        if ($driver === 'mysql') {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                self::envValue($prefix, 'DB_HOST', 'localhost'),
                self::envValue($prefix, 'DB_PORT', '3306'),
                self::envValue($prefix, 'DB_NAME')
            );
            $user = self::envValue($prefix, 'DB_USER');
            $pass = self::envValue($prefix, 'DB_PASS', '');
        } elseif ($driver === 'pgsql') {
            $dsn = sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                self::envValue($prefix, 'DB_HOST', 'localhost'),
                self::envValue($prefix, 'DB_PORT', '5432'),
                self::envValue($prefix, 'DB_NAME')
            );
            $user = self::envValue($prefix, 'DB_USER');
            $pass = self::envValue($prefix, 'DB_PASS', '');
        } else {
            $path = self::envValue(
                $prefix,
                'DB_PATH',
                __DIR__ . '/../storage/database.sqlite'
            );
            if (!str_starts_with($path, '/') && !preg_match('/^[A-Za-z]:\\\\/', $path)) {
                $path = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
            }
            $dsn = 'sqlite:' . $path;
            $user = null;
            $pass = null;
        }

        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    private static function envValue(string $prefix, string $key, ?string $default = null): string
    {
        $prefixed = getenv($prefix . '_' . $key);
        if (is_string($prefixed) && $prefixed !== '') {
            return $prefixed;
        }

        if ($prefix === 'SOURCE') {
            $configValue = Config::get($key, $default);
            if (is_string($configValue) && $configValue !== '') {
                return $configValue;
            }
            if ($default !== null) {
                return $default;
            }
            return (string) Config::require($key);
        }

        if ($default !== null) {
            return $default;
        }

        throw new RuntimeException("Missing required env: {$prefix}_{$key}");
    }

    private static function ensureTargetSchema(PDO $target): void
    {
        $schemaFile = __DIR__ . '/../sql/postgres/schema.sql';
        if (!is_file($schemaFile)) {
            throw new RuntimeException('Missing postgres schema file: ' . $schemaFile);
        }

        $sql = file_get_contents($schemaFile);
        if (!is_string($sql) || trim($sql) === '') {
            throw new RuntimeException('Postgres schema file is empty');
        }

        $target->exec($sql);
    }

    private static function copyTable(PDO $source, PDO $target, string $table): int
    {
        $rows = $source->query('SELECT * FROM ' . $table)->fetchAll();
        if (!is_array($rows) || $rows === []) {
            return 0;
        }

        $target->exec('TRUNCATE TABLE ' . $table . ' RESTART IDENTITY CASCADE');

        $first = self::mapRowForPostgres($table, $rows[0]);
        $columns = array_keys($first);
        $columnSql = implode(', ', $columns);
        $placeholders = implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns));
        $stmt = $target->prepare(
            "INSERT INTO {$table} ({$columnSql}) VALUES ({$placeholders})"
        );

        $count = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $mapped = self::mapRowForPostgres($table, $row);
            $params = [];
            foreach ($columns as $column) {
                $params[$column] = $mapped[$column] ?? null;
            }
            $stmt->execute($params);
            $count++;
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function mapRowForPostgres(string $table, array $row): array
    {
        if ($table !== 'google_tokens') {
            return $row;
        }

        foreach (self::PATIENT_COLUMN_MAP as $from => $to) {
            if (array_key_exists($from, $row) && !array_key_exists($to, $row)) {
                $row[$to] = $row[$from];
                unset($row[$from]);
            }
        }

        return $row;
    }

    private static function resetSequences(PDO $target): void
    {
        $serialTables = [
            'google_tokens' => 'id',
            'google_event_vacations' => 'id',
            'google_calendar_bookings' => 'id',
            'import_future_vacations_backfill_slots' => 'id',
            'doctor_external_connections' => 'id',
            'monitor_events' => 'id',
        ];

        foreach ($serialTables as $table => $column) {
            $target->exec(
                "SELECT setval(
                    pg_get_serial_sequence('{$table}', '{$column}'),
                    COALESCE((SELECT MAX({$column}) FROM {$table}), 1)
                )"
            );
        }
    }
}

exit(PostgresMigrator::run());
