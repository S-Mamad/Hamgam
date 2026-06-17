<?php

declare(strict_types=1);

/**
 * آدرس‌های بازگشت پذیرش۲۴ / Hamdast.
 */
final class HamgamRedirects
{
    /** لانچر با باز شدن خودکار اپ (iframe تنظیمات) — بعد از OAuth */
    public static function launcherAppOpenUrl(): string
    {
        $direct = Config::get('REDIRECT_LAUNCHER_DIRECT');
        if (is_string($direct) && trim($direct) !== '') {
            return trim($direct);
        }

        return Config::require('REDIRECT_LAUNCHER');
    }

    /** صفحهٔ اصلی لانچر — بعد از خروج / disconnect */
    public static function launcherHomeUrl(): string
    {
        return Config::require('REDIRECT_LAUNCHER');
    }
}
