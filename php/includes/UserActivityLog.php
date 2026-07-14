<?php

declare(strict_types=1);

/**
 * ثبت یکپارچه فعالیت‌های هر پزشک در monitor_events — برای debug و داشبورد.
 */
final class UserActivityLog
{
    public static function record(
        string $userId,
        string $action,
        string $summary,
        string $category = 'system',
        string $level = 'info',
        ?array $context = null,
        ?string $entityType = null,
        ?string $entityId = null,
        ?string $channel = null,
    ): void {
        $userId = GoogleTokensRepository::normalizeUserId($userId);
        if ($userId === '') {
            return;
        }

        MonitorService::record([
            'user_id' => $userId,
            'action' => $action,
            'message' => $summary,
            'category' => $category,
            'level' => $level,
            'context' => $context,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'channel' => $channel ?? self::defaultChannel($category),
        ]);
    }

    public static function auth(string $userId, string $action, string $summary, string $level = 'info', ?array $context = null): void
    {
        self::record($userId, $action, $summary, 'auth', $level, $context, channel: 'hamgam/auth');
    }

    public static function api(string $userId, string $action, string $summary, string $level = 'info', ?array $context = null): void
    {
        self::record($userId, $action, $summary, 'api', $level, $context, channel: 'hamgam');
    }

    public static function vacation(string $userId, string $action, string $summary, string $level = 'info', ?array $context = null, ?string $entityType = null, ?string $entityId = null): void
    {
        self::record($userId, $action, $summary, 'vacation', $level, $context, $entityType, $entityId, 'google-vacation');
    }

    public static function appointment(string $userId, string $action, string $summary, string $level = 'info', ?array $context = null, ?string $bookId = null): void
    {
        self::record($userId, $action, $summary, 'appointment', $level, $context, 'book_id', $bookId, 'paziresh24-hamgam');
    }

    public static function integration(string $userId, string $provider, string $action, string $summary, string $level = 'info', ?array $context = null): void
    {
        self::record($userId, $action, $summary, 'integration', $level, $context, channel: 'integrations/' . $provider);
    }

    private static function defaultChannel(string $category): string
    {
        return match ($category) {
            'auth' => 'hamgam/auth',
            'api' => 'hamgam',
            'vacation' => 'google-vacation',
            'appointment' => 'paziresh24-hamgam',
            'webhook' => 'webhook',
            'integration' => 'integrations',
            'cron' => 'cron',
            default => 'system',
        };
    }
}
