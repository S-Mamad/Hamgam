<?php

declare(strict_types=1);

final class Paziresh24WebhookPayload
{
    /**
     * @param array<string, mixed> $payload
     */
    public static function extractEventType(array $payload): string
    {
        foreach (['event', 'type', 'event_type'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return self::normalizeEventType(trim($value));
            }
        }

        return 'provider.appointment';
    }

    public static function normalizeEventType(string $eventType): string
    {
        return match ($eventType) {
            'provider.appointment.canceled',
            'provider.appointment.deleted',
            'provider.appointment.delete' => 'provider.appointment.cancelled',
            default => $eventType,
        };
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    public static function extractBooking(array $payload): ?array
    {
        $data = $payload['data'] ?? null;
        if (is_array($data)) {
            foreach (['appointment', 'booking'] as $nestedKey) {
                $nested = $data[$nestedKey] ?? null;
                if (is_array($nested)) {
                    return $nested;
                }
            }

            return $data;
        }

        if (self::extractBookId($payload) !== null || self::extractDoctorUserId($payload) !== null) {
            return $payload;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $booking
     */
    public static function extractBookId(array $booking): ?string
    {
        foreach (['book_id', 'id', 'bookId', 'appointment_id'] as $key) {
            $value = $booking[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $booking
     */
    public static function extractDoctorUserId(array $booking): ?string
    {
        foreach (['doctor_user_id', 'user_id', 'provider_user_id', 'doctor_id'] as $key) {
            $value = $booking[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        foreach (['doctor', 'provider', 'user', 'appointment', 'booking'] as $nestedKey) {
            $nested = $booking[$nestedKey] ?? null;
            if (!is_array($nested)) {
                continue;
            }

            foreach (['user_id', 'id', 'doctor_user_id'] as $key) {
                $value = $nested[$key] ?? null;
                if (is_scalar($value) && trim((string) $value) !== '') {
                    return trim((string) $value);
                }
            }
        }

        return null;
    }
}
