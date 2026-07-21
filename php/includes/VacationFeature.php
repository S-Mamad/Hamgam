<?php

declare(strict_types=1);

final class VacationFeature
{
    public static function isEnabled(): bool
    {
        return Config::getBool('VACATION_ENABLED', false);
    }
}
