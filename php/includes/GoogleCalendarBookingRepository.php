<?php

declare(strict_types=1);

final class GoogleCalendarBookingRepository
{
    public static function hasProcessedBooking(string $userId, string $bookId): bool
    {
        $userId = GoogleTokensRepository::normalizeUserId($userId);
        $bookId = trim($bookId);
        if ($bookId === '') {
            return false;
        }

        $stmt = Database::connection()->prepare(
            'SELECT id FROM google_calendar_bookings
             WHERE paziresh24_user_id = :user_id AND book_id = :book_id
             LIMIT 1'
        );
        $stmt->execute([
            'user_id' => $userId,
            'book_id' => $bookId,
        ]);

        return $stmt->fetch() !== false;
    }

    public static function recordProcessedBooking(string $userId, string $bookId): void
    {
        $userId = GoogleTokensRepository::normalizeUserId($userId);
        $bookId = trim($bookId);
        if ($bookId === '') {
            return;
        }

        $driver = Config::get('DB_DRIVER', 'sqlite');

        if ($driver === 'mysql') {
            $stmt = Database::connection()->prepare(
                'INSERT INTO google_calendar_bookings (paziresh24_user_id, book_id)
                 VALUES (:user_id, :book_id)
                 ON DUPLICATE KEY UPDATE book_id = VALUES(book_id)'
            );
        } else {
            $stmt = Database::connection()->prepare(
                'INSERT OR IGNORE INTO google_calendar_bookings (paziresh24_user_id, book_id)
                 VALUES (:user_id, :book_id)'
            );
        }

        $stmt->execute([
            'user_id' => $userId,
            'book_id' => $bookId,
        ]);
    }

    public static function removeProcessedBooking(string $userId, string $bookId): void
    {
        $userId = GoogleTokensRepository::normalizeUserId($userId);
        $bookId = trim($bookId);
        if ($bookId === '') {
            return;
        }

        $stmt = Database::connection()->prepare(
            'DELETE FROM google_calendar_bookings
             WHERE paziresh24_user_id = :user_id AND book_id = :book_id'
        );
        $stmt->execute([
            'user_id' => $userId,
            'book_id' => $bookId,
        ]);
    }

    public static function clearAllForUser(string $userId): void
    {
        $userId = GoogleTokensRepository::normalizeUserId($userId);
        if ($userId === '') {
            return;
        }

        $stmt = Database::connection()->prepare(
            'DELETE FROM google_calendar_bookings WHERE paziresh24_user_id = :user_id'
        );
        $stmt->execute(['user_id' => $userId]);
    }
}
