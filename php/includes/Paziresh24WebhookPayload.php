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
            'provider.appointment.delete',
            'appointment.canceled',
            'appointment.cancelled',
            'appointment.deleted' => 'provider.appointment.cancelled',
            'appointment.updated' => 'provider.appointment.updated',
            'appointment' => 'provider.appointment',
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

            $record = $data['after_update_record'] ?? $data['book_record'] ?? null;
            if (is_array($record)) {
                return self::mergeBookingRecord($data, $record);
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

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private static function mergeBookingRecord(array $data, array $record): array
    {
        $merged = $record;

        foreach (
            [
                'book_id',
                'doctor_user_id',
                'doctor_id',
                'center_id',
                'center_name',
                'center_address',
                'center_tell',
                'patient_name',
                'patient_cell',
                'ref_id',
                'time',
                'from',
                'to',
                'from_date',
                'from_hour',
                'book_date',
                'book_time',
                'duration',
                'book_duration',
            ] as $key
        ) {
            $value = $data[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                $merged[$key] = $value;
            }
        }

        if (!isset($merged['book_id'])) {
            $bookId = self::extractBookId($record);
            if ($bookId !== null) {
                $merged['book_id'] = $bookId;
            }
        }

        return $merged;
    }
}
