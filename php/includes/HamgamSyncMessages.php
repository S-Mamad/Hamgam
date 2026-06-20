<?php

declare(strict_types=1);

/**
 * پیام‌های خطا و هشدار همگام‌سازی Google Calendar (فارسی برای UI).
 */
final class HamgamSyncMessages
{
    /** @var array<string, string> */
    private const MESSAGES = [
        'no_token_row' => 'اتصال Google یافت نشد. دوباره حساب Google را متصل کنید.',
        'google_token_refresh_failed' => 'همگام‌سازی Google Calendar ناموفق بود. اتصال Google را بررسی کنید.',
        'watch_registration_failed' => 'ثبت همگام‌سازی زنده تقویم Google ناموفق بود. تنظیمات را دوباره ذخیره کنید.',
        'calendar_channel_missing' => 'شناسه کانال همگام‌سازی تقویم Google ثبت نشده است. یک‌بار تنظیمات را ذخیره کنید.',
        'calendar_resource_missing' => 'شناسه منبع همگام‌سازی تقویم Google ثبت نشده است. یک‌بار تنظیمات را ذخیره کنید.',
        'sync_failed' => 'خطا در همگام‌سازی Google Calendar. چند لحظه بعد دوباره تلاش کنید.',
        'backfill_failed' => 'خطا در خواندن رویدادهای تقویم Google.',
        'backfill_not_run' => 'خواندن رویدادهای آینده انجام نشد. اتصال Google یا مراکز درمانی را بررسی کنید.',
        'backfill_no_centers' => 'مرکز درمانی برای ثبت مرخصی یافت نشد.',
        'backfill_token_refresh_failed' => 'دسترسی به تقویم Google منقضی شده. اتصال را دوباره برقرار کنید.',
        'backfill_partial_fail' => 'برخی رویدادهای تقویم ثبت نشدند.',
    ];

    /**
     * @return array{code: string, message: string}
     */
    public static function warning(string $code, ?string $detail = null): array
    {
        $message = self::MESSAGES[$code] ?? 'خطا در همگام‌سازی Google Calendar.';

        if ($code === 'backfill_partial_fail' && $detail !== null && ctype_digit($detail)) {
            $count = (int) $detail;
            if ($count > 0) {
                $message = $count . ' رویداد تقویم ثبت نشد. دوباره تلاش کنید.';
            }
        }

        return ['code' => $code, 'message' => $message];
    }

    /**
     * @param list<array{code: string, message: string}> $warnings
     */
    public static function hasErrors(array $warnings): bool
    {
        return $warnings !== [];
    }
}
