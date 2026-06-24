<?php

declare(strict_types=1);

/**
 * E2E: multi-day span (27-30) + recurring instance moved into span — real Google + Paziresh24.
 *
 * Optional env overrides (for CI/local):
 *   HAMGAM_E2E_USER_ID, HAMGAM_E2E_REFRESH, HAMGAM_E2E_HAMDAST, HAMGAM_E2E_CENTER
 *
 * CLI: php -c dev/php.ini php/tools/test-vacation-span-recurring-move-e2e.php
 */

require_once __DIR__ . '/../includes/Config.php';
require_once __DIR__ . '/../includes/HttpClient.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Paziresh24Api.php';
require_once __DIR__ . '/../includes/GoogleTokensRepository.php';
require_once __DIR__ . '/../includes/GoogleCalendar.php';
require_once __DIR__ . '/../includes/vacation-bootstrap.php';

Config::load(__DIR__ . '/../.env');
date_default_timezone_set('Asia/Tehran');
ini_set('display_errors', '1');
error_reporting(E_ALL);

hamgam_load_vacation_modules();
require_once __DIR__ . '/../google-vacation/VacationSyncService.php';

$passed = 0;
$failed = 0;

function spanE2eAssert(string $name, bool $ok, mixed $detail = null): void
{
    global $passed, $failed;

    if ($ok) {
        $passed++;
        echo 'PASS ' . $name . PHP_EOL;
    } else {
        $failed++;
        echo 'FAIL ' . $name . PHP_EOL;
    }

    if ($detail !== null) {
        $line = is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        echo '  ' . $line . PHP_EOL;
    }
}

function vacationCovers(int $vacationFrom, int $vacationTo, int $needleFrom, int $needleTo): bool
{
    return $vacationFrom <= $needleFrom && $vacationTo >= $needleTo;
}

function findVacationCovering(array $vacations, int $from, int $to): ?array
{
    foreach ($vacations as $row) {
        if (!is_array($row)) {
            continue;
        }
        $vf = (int) ($row['from'] ?? $row['vacation_from'] ?? 0);
        $vt = (int) ($row['to'] ?? $row['vacation_to'] ?? 0);
        if ($vf > 0 && $vt > $vf && vacationCovers($vf, $vt, $from, $to)) {
            return ['from' => $vf, 'to' => $vt];
        }
    }

    return null;
}

function listVacationRows(string $token, string $centerId, int $from, int $to): array
{
    $body = Paziresh24VacationApi::listVacations($token, $centerId, $from, $to);
    if (!is_array($body)) {
        return [];
    }

    if (isset($body['data']) && is_array($body['data'])) {
        return $body['data'];
    }

    if (array_is_list($body)) {
        return $body;
    }

    return [];
}

$userId = getenv('HAMGAM_E2E_USER_ID') ?: '23489442';
$refreshToken = getenv('HAMGAM_E2E_REFRESH') ?: '';
$hamdastToken = getenv('HAMGAM_E2E_HAMDAST') ?: '';
$centerId = getenv('HAMGAM_E2E_CENTER') ?: '5532';

$spanEventId = null;
$recurringMasterId = null;
$movedInstanceId = null;
$spanFrom = 0;
$spanTo = 0;
$slotFrom = 0;
$slotTo = 0;
$stamp = date('His');

echo '=== Span + recurring move E2E ===' . PHP_EOL;

try {
    $tokenRow = GoogleTokensRepository::findByUserId($userId);
    if ($refreshToken === '' && is_array($tokenRow)) {
        $refreshToken = (string) ($tokenRow['google_refresh_token'] ?? '');
    }
    if ($hamdastToken === '' && is_array($tokenRow)) {
        $hamdastToken = (string) ($tokenRow['hamdast_access_token'] ?? '');
    }

    spanE2eAssert('credentials available', $refreshToken !== '' && $hamdastToken !== '');

    if ($refreshToken === '' || $hamdastToken === '') {
        throw new RuntimeException('missing credentials');
    }

    GoogleTokensRepository::upsertHamdastAccessToken($userId, $hamdastToken);
    $pdo = Database::connection();
    $pdo->prepare(
        'UPDATE google_tokens SET google_refresh_token = :rt, center_id = :center, auto_vacation = 1 WHERE paziresh24_user_id = :uid'
    )->execute(['rt' => $refreshToken, 'center' => $centerId, 'uid' => $userId]);

    $tokenRow = GoogleTokensRepository::findByUserId($userId);
    spanE2eAssert('token row ready', is_array($tokenRow));

    $googleTokenData = GoogleCalendar::refreshAccessToken($refreshToken);
    $googleAccess = is_array($googleTokenData) ? (string) ($googleTokenData['access_token'] ?? '') : '';
    spanE2eAssert('google access token', $googleAccess !== '');

    $vacationCenter = Paziresh24VacationApi::resolveVacationCenter($hamdastToken);
    $vacationCenters = $vacationCenter !== null
        ? [$vacationCenter]
        : [[
            'medical_center_id' => $centerId,
            'user_center_id' => null,
            'name' => 'E2E center',
        ]];

    $tz = new DateTimeZone('Asia/Tehran');
    $anchor = new DateTimeImmutable('+14 days', $tz);
    $spanStart = $anchor->setTime(0, 0);
    $spanEnd = $spanStart->modify('+3 days');
    $slotDay = $spanStart->modify('-2 days');
    $slotStart = $slotDay->setTime(10, 30);
    $slotEnd = $slotStart->modify('+1 hour');
    $movedDay = $spanStart->modify('+1 day');
    $movedStart = $movedDay->setTime(10, 30);
    $movedEnd = $movedStart->modify('+1 hour');

    $spanCreated = GoogleCalendar::createEventReturningBody($googleAccess, [
        'summary' => 'E2E span ' . $stamp,
        'description' => 'auto test',
        'start' => ['date' => $spanStart->format('Y-m-d')],
        'end' => ['date' => $spanEnd->format('Y-m-d')],
    ]);
    spanE2eAssert('create multi-day span event', is_array($spanCreated));
    $spanEventId = is_string($spanCreated['id'] ?? null) ? $spanCreated['id'] : '';
    $spanParsed = is_array($spanCreated) ? GoogleEventParser::parseEvent($spanCreated) : null;
    spanE2eAssert('parse span event', is_array($spanParsed));
    $spanFrom = is_array($spanParsed) ? $spanParsed['start_ts'] : 0;
    $spanTo = is_array($spanParsed) ? $spanParsed['end_ts'] : 0;

    $recurringCreated = GoogleCalendar::createEventReturningBody($googleAccess, [
        'summary' => 'E2E recurring ' . $stamp,
        'description' => 'auto test',
        'start' => [
            'dateTime' => $slotStart->format('Y-m-d\TH:i:s'),
            'timeZone' => 'Asia/Tehran',
        ],
        'end' => [
            'dateTime' => $slotEnd->format('Y-m-d\TH:i:s'),
            'timeZone' => 'Asia/Tehran',
        ],
        'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=4'],
    ]);
    spanE2eAssert('create weekly recurring event', is_array($recurringCreated));
    $recurringMasterId = is_string($recurringCreated['id'] ?? null) ? $recurringCreated['id'] : '';

    sleep(2);

    $instances = GoogleCalendarWatch::listRecurringEventInstances(
        $googleAccess,
        $recurringMasterId,
        $slotStart->modify('-1 day')->format('c'),
        $slotStart->modify('+14 days')->format('c')
    );
    spanE2eAssert('list recurring instances', $instances !== []);
    $firstInstance = $instances[0] ?? null;
    spanE2eAssert('first instance found', is_array($firstInstance));
    $firstInstanceId = is_array($firstInstance) ? (string) ($firstInstance['id'] ?? '') : '';
    $firstParsed = is_array($firstInstance) ? GoogleEventParser::parseEvent($firstInstance) : null;
    spanE2eAssert('first instance timed (not midnight)', is_array($firstParsed) && (int) date('H', $firstParsed['start_ts']) === 10);
    $slotFrom = is_array($firstParsed) ? $firstParsed['start_ts'] : 0;
    $slotTo = is_array($firstParsed) ? $firstParsed['end_ts'] : 0;

    VacationSyncService::syncSingleEvent($userId, $tokenRow ?? [], $spanCreated, true, $vacationCenters, $hamdastToken, false, $googleAccess);
    VacationSyncService::syncSingleEvent($userId, $tokenRow ?? [], $firstInstance, true, $vacationCenters, $hamdastToken, false, $googleAccess);

    $listFrom = $spanFrom - 86400;
    $listTo = $spanTo + 86400;
    $vacationsAfterCreate = listVacationRows($hamdastToken, $centerId, $listFrom, $listTo);
    spanE2eAssert('span vacation exists after create', findVacationCovering($vacationsAfterCreate, $spanFrom, $spanTo) !== null);
    spanE2eAssert('recurring slot vacation exists after create', findVacationCovering($vacationsAfterCreate, $slotFrom, $slotTo) !== null);

    $movedInstance = GoogleCalendar::updateEventReturningBody($googleAccess, $firstInstanceId, [
        'start' => [
            'dateTime' => $movedStart->format('Y-m-d\TH:i:s'),
            'timeZone' => 'Asia/Tehran',
        ],
        'end' => [
            'dateTime' => $movedEnd->format('Y-m-d\TH:i:s'),
            'timeZone' => 'Asia/Tehran',
        ],
    ]);
    spanE2eAssert('move instance into span (+1 day inside span)', is_array($movedInstance));
    $movedInstanceId = is_array($movedInstance) ? (string) ($movedInstance['id'] ?? $firstInstanceId) : $firstInstanceId;

    $partialWebhookPayload = is_array($movedInstance) ? $movedInstance : [];
    if (is_array($partialWebhookPayload['start'] ?? null) && is_string($partialWebhookPayload['start']['dateTime'] ?? null)) {
        $partialWebhookPayload['start']['date'] = $movedDay->format('Y-m-d');
        $partialWebhookPayload['end']['date'] = $movedDay->modify('+1 day')->format('Y-m-d');
    }

    $movedParsed = GoogleEventParser::parseEvent($partialWebhookPayload);
    $expectedMovedFrom = $movedStart->getTimestamp();
    spanE2eAssert('partial payload still parses 10:30', is_array($movedParsed) && $movedParsed['start_ts'] === $expectedMovedFrom);

    VacationSyncService::syncSingleEvent($userId, $tokenRow ?? [], $partialWebhookPayload, true, $vacationCenters, $hamdastToken, false, $googleAccess);

    $movedFrom = $expectedMovedFrom;
    $movedTo = $movedEnd->getTimestamp();
    $vacationsAfterMove = listVacationRows($hamdastToken, $centerId, $listFrom, $listTo);
    $movedVacation = findVacationCovering($vacationsAfterMove, $movedFrom, $movedTo);
    spanE2eAssert('moved recurring vacation at 10:30 inside span', $movedVacation !== null, $movedVacation);
    if (is_array($movedVacation)) {
        spanE2eAssert(
            'moved vacation start is not midnight',
            (int) date('H', $movedVacation['from']) === 10 && (int) date('i', $movedVacation['from']) === 30,
            date('Y-m-d H:i:s', $movedVacation['from'])
        );
    }

    spanE2eAssert(
        'span vacation still covers 27-30 equivalent window',
        findVacationCovering($vacationsAfterMove, $spanFrom, $spanTo) !== null
    );
} catch (Throwable $e) {
    spanE2eAssert('unexpected exception', false, $e->getMessage());
} finally {
    if (isset($googleAccess) && is_string($googleAccess) && $googleAccess !== '') {
        if (is_string($spanEventId) && $spanEventId !== '') {
            GoogleCalendar::deleteEvent($googleAccess, $spanEventId);
        }
        if (is_string($recurringMasterId) && $recurringMasterId !== '') {
            GoogleCalendar::deleteEvent($googleAccess, $recurringMasterId);
        }
    }

    if (isset($hamdastToken, $centerId, $spanFrom, $spanTo) && $hamdastToken !== '' && $spanFrom > 0) {
        Paziresh24VacationApi::deleteVacation($hamdastToken, $centerId, $spanFrom, $spanTo);
    }
    if (isset($hamdastToken, $centerId, $slotFrom, $slotTo) && $hamdastToken !== '' && $slotFrom > 0) {
        Paziresh24VacationApi::deleteVacation($hamdastToken, $centerId, $slotFrom, $slotTo);
    }
    if (isset($hamdastToken, $centerId, $movedFrom, $movedTo) && $hamdastToken !== '' && ($movedFrom ?? 0) > 0) {
        Paziresh24VacationApi::deleteVacation($hamdastToken, $centerId, $movedFrom, $movedTo);
    }

    if (isset($userId, $spanEventId) && $spanEventId !== '') {
        GoogleVacationRepository::removeProcessedEvent($userId, $spanEventId, $centerId);
    }
    if (isset($userId, $firstInstanceId) && ($firstInstanceId ?? '') !== '') {
        GoogleVacationRepository::removeProcessedEvent($userId, $firstInstanceId, $centerId);
    }
    if (isset($userId, $movedInstanceId) && ($movedInstanceId ?? '') !== '' && $movedInstanceId !== ($firstInstanceId ?? '')) {
        GoogleVacationRepository::removeProcessedEvent($userId, $movedInstanceId, $centerId);
    }
}

echo PHP_EOL . "Total: {$passed} passed, {$failed} failed" . PHP_EOL;
exit($failed > 0 ? 1 : 0);
