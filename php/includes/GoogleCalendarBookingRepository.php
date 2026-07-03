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

    public static function getGoogleEventId(string $userId, string $bookId): ?string
    {
        $userId = GoogleTokensRepository::normalizeUserId($userId);
        $bookId = trim($bookId);
        if ($bookId === '') {
            return null;
        }

        $stmt = Database::connection()->prepare(
            'SELECT google_event_id FROM google_calendar_bookings
             WHERE paziresh24_user_id = :user_id AND book_id = :book_id
             LIMIT 1'
        );
        $stmt->execute([
            'user_id' => $userId,
            'book_id' => $bookId,
        ]);

        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        $eventId = $row['google_event_id'] ?? null;

        return is_string($eventId) && trim($eventId) !== '' ? trim($eventId) : null;
    }

    public static function getBookIdByGoogleEventId(string $userId, string $googleEventId): ?string
    {
        $userId = GoogleTokensRepository::normalizeUserId($userId);
        $googleEventId = trim($googleEventId);
        if ($googleEventId === '') {
            return null;
        }

        $stmt = Database::connection()->prepare(
            'SELECT book_id FROM google_calendar_bookings
             WHERE paziresh24_user_id = :user_id AND google_event_id = :google_event_id
             LIMIT 1'
        );
        $stmt->execute([
            'user_id' => $userId,
            'google_event_id' => $googleEventId,
        ]);

        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        $bookId = $row['book_id'] ?? null;

        return is_string($bookId) && trim($bookId) !== '' ? trim($bookId) : null;
    }

    /**
     * @return array<int, string>
     */
    public static function listBookIdsForUser(string $userId): array
    {
        $userId = GoogleTokensRepository::normalizeUserId($userId);
        if ($userId === '') {
            return [];
        }

        $stmt = Database::connection()->prepare(
            'SELECT book_id FROM google_calendar_bookings
             WHERE paziresh24_user_id = :user_id'
        );
        $stmt->execute(['user_id' => $userId]);

        $bookIds = [];
        while ($row = $stmt->fetch()) {
            if (!is_array($row)) {
                continue;
            }

            $bookId = $row['book_id'] ?? null;
            if (is_string($bookId) && trim($bookId) !== '') {
                $bookIds[] = trim($bookId);
            }
        }

        return $bookIds;
    }

    public static function recordProcessedBooking(
        string $userId,
        string $bookId,
        ?string $googleEventId = null
    ): void {
        $userId = GoogleTokensRepository::normalizeUserId($userId);
        $bookId = trim($bookId);
        if ($bookId === '') {
            return;
        }

        $googleEventId = is_string($googleEventId) ? trim($googleEventId) : '';
        $driver = Config::get('DB_DRIVER', 'sqlite');

        if ($driver === 'mysql') {
            $stmt = Database::connection()->prepare(
                'INSERT INTO google_calendar_bookings (paziresh24_user_id, book_id, google_event_id)
                 VALUES (:user_id, :book_id, :google_event_id)
                 ON DUPLICATE KEY UPDATE
                    google_event_id = CASE
                        WHEN VALUES(google_event_id) IS NOT NULL AND VALUES(google_event_id) != \'\'
                            THEN VALUES(google_event_id)
                        ELSE google_event_id
                    END'
            );
        } else {
            $stmt = Database::connection()->prepare(
                'INSERT INTO google_calendar_bookings (paziresh24_user_id, book_id, google_event_id)
                 VALUES (:user_id, :book_id, :google_event_id)
                 ON CONFLICT(paziresh24_user_id, book_id) DO UPDATE SET
                    google_event_id = CASE
                        WHEN excluded.google_event_id IS NOT NULL AND excluded.google_event_id != \'\'
                            THEN excluded.google_event_id
                        ELSE google_event_id
                    END'
            );
        }

        $stmt->execute([
            'user_id' => $userId,
            'book_id' => $bookId,
            'google_event_id' => $googleEventId !== '' ? $googleEventId : null,
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
