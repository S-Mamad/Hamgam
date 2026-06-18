<?php

declare(strict_types=1);

/**
 * End-to-end scenario tests: vacation center selection, per-center create/delete.
 *
 * Usage: php php/tools/test-vacation-centers-scenarios.php
 */

require_once __DIR__ . '/../includes/bootstrap.php';

$failed = 0;

function assertTrue(bool $condition, string $message): void
{
    global $failed;

    if ($condition) {
        echo 'OK   ' . $message . PHP_EOL;
        return;
    }

    echo 'FAIL ' . $message . PHP_EOL;
    $failed++;
}

echo '=== Vacation center filter (create path) ===' . PHP_EOL;

$centerA = 'e5d0fa25-a8e1-40db-a957-97aa0af1c0ee';
$centerB = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890';
$available = [
    [
        'medical_center_id' => $centerA,
        'user_center_id' => 'uc-a',
        'name' => 'کلینیک A',
        'is_active_booking' => true,
    ],
    [
        'medical_center_id' => $centerB,
        'user_center_id' => 'uc-b',
        'name' => 'کلینیک B',
        'is_active_booking' => true,
    ],
];

$rowAll = [
    'vacation_sync_centers' => json_encode(['mode' => 'all', 'center_ids' => []], JSON_UNESCAPED_UNICODE),
];
$filteredAll = GoogleTokensRepository::filterVacationCentersForSync($rowAll, $available);
assertTrue(count($filteredAll) === 2, 'mode=all syncs every available center');

$rowOne = [
    'vacation_sync_centers' => json_encode(['mode' => 'selected', 'center_ids' => [$centerA]], JSON_UNESCAPED_UNICODE),
];
$filteredOne = GoogleTokensRepository::filterVacationCentersForSync($rowOne, $available);
assertTrue(count($filteredOne) === 1, 'mode=selected syncs only listed centers');
assertTrue(
    ($filteredOne[0]['medical_center_id'] ?? '') === $centerA,
    'selected filter keeps the chosen center only'
);

$rowOther = [
    'vacation_sync_centers' => json_encode(['mode' => 'selected', 'center_ids' => [$centerB]], JSON_UNESCAPED_UNICODE),
];
$filteredOther = GoogleTokensRepository::filterVacationCentersForSync($rowOther, $available);
assertTrue(
    ($filteredOther[0]['medical_center_id'] ?? '') === $centerB,
    'switching selection changes which center receives new vacations'
);

$rowEmptySelected = [
    'vacation_sync_centers' => json_encode(['mode' => 'selected', 'center_ids' => []], JSON_UNESCAPED_UNICODE),
];
$filteredEmpty = GoogleTokensRepository::filterVacationCentersForSync($rowEmptySelected, $available);
assertTrue($filteredEmpty === [], 'empty selected list creates no new vacations');

echo PHP_EOL . '=== Settings parse / save validation ===' . PHP_EOL;

$validBody = [
    'colorId' => '9',
    'fullName' => true,
    'centerName' => false,
    'datetime' => false,
    'nationalId' => false,
    'phone' => false,
    'autoVacation' => true,
    'importFutureVacations' => false,
    'cancelAppointmentOnEventDelete' => true,
    'cancelConflictingAppointments' => true,
    'vacationSyncCenters' => ['mode' => 'selected', 'centerIds' => [$centerA]],
];

$ref = new ReflectionClass(GoogleTokensRepository::class);
$parseMethod = $ref->getMethod('parseSettingsBody');
$parseMethod->setAccessible(true);
$parsed = $parseMethod->invoke(null, $validBody);
assertTrue(is_array($parsed), 'valid vacationSyncCenters payload parses');
assertTrue(
    ($parsed['vacation_sync_centers']['center_ids'][0] ?? '') === $centerA,
    'parsed center id preserved'
);

$invalidBody = $validBody;
$invalidBody['vacationSyncCenters'] = ['mode' => 'selected', 'centerIds' => []];
$parsedInvalid = $parseMethod->invoke(null, $invalidBody);
assertTrue($parsedInvalid === null, 'empty center list rejected when autoVacation on');

$allBody = $validBody;
$allBody['vacationSyncCenters'] = ['mode' => 'all', 'centerIds' => []];
$parsedAllBody = $parseMethod->invoke(null, $allBody);
assertTrue(
    is_array($parsedAllBody) && ($parsedAllBody['vacation_sync_centers']['mode'] ?? '') === 'all',
    'mode=all still accepted for legacy compatibility'
);

echo PHP_EOL . '=== Per-center delete targets (tracked rows) ===' . PHP_EOL;

try {
  $pdo = Database::connection();
  $driver = Config::get('DB_DRIVER', 'sqlite');
  $doctorId = '999001';

  if ($driver === 'mysql') {
      $pdo->exec('CREATE TABLE IF NOT EXISTS google_event_vacations (
          id INT AUTO_INCREMENT PRIMARY KEY,
          paziresh24_user_id VARCHAR(64) NOT NULL,
          google_event_id VARCHAR(256) NOT NULL,
          medical_center_id VARCHAR(64) NOT NULL,
          vacation_from BIGINT NOT NULL,
          vacation_to BIGINT NOT NULL,
          UNIQUE KEY uq_event_center (paziresh24_user_id, google_event_id, medical_center_id)
      )');
      $pdo->exec('CREATE TABLE IF NOT EXISTS import_future_vacations_backfill_slots (
          id INT AUTO_INCREMENT PRIMARY KEY,
          paziresh24_user_id VARCHAR(64) NOT NULL,
          google_event_id VARCHAR(256) NOT NULL,
          medical_center_id VARCHAR(64) NOT NULL,
          vacation_from BIGINT NOT NULL,
          vacation_to BIGINT NOT NULL,
          deleted_at TIMESTAMP NULL
      )');
  } else {
      $pdo->exec('CREATE TABLE IF NOT EXISTS google_event_vacations (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          paziresh24_user_id TEXT NOT NULL,
          google_event_id TEXT NOT NULL,
          medical_center_id TEXT NOT NULL,
          vacation_from INTEGER NOT NULL,
          vacation_to INTEGER NOT NULL,
          UNIQUE (paziresh24_user_id, google_event_id, medical_center_id)
      )');
      $pdo->exec('CREATE TABLE IF NOT EXISTS import_future_vacations_backfill_slots (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          paziresh24_user_id TEXT NOT NULL,
          google_event_id TEXT NOT NULL,
          medical_center_id TEXT NOT NULL,
          vacation_from INTEGER NOT NULL,
          vacation_to INTEGER NOT NULL,
          deleted_at TEXT NULL
      )');
  }

  $pdo->prepare('DELETE FROM google_event_vacations WHERE paziresh24_user_id = :id')
      ->execute(['id' => $doctorId]);
  $pdo->prepare('DELETE FROM import_future_vacations_backfill_slots WHERE paziresh24_user_id = :id')
      ->execute(['id' => $doctorId]);

  GoogleVacationRepository::recordProcessedEvent($doctorId, 'evt-1', 'مرخصی', 1000, 2000, null, $centerA);
  GoogleVacationRepository::recordProcessedEvent($doctorId, 'evt-1', 'مرخصی', 1000, 2000, null, $centerB);
  ImportFutureVacationsRepository::upsertBackfillSlotForEvent($doctorId, 'evt-2', $centerA, 3000, 4000);

  $targets = ImportFutureVacationsRepository::listDeletableImportVacationTargets($doctorId);
  assertTrue(count($targets) === 2, 'delete targets include tracked rows per center');

  $centerIds = array_map(static fn(array $t): string => $t['medical_center_id'], $targets);
  assertTrue(in_array($centerA, $centerIds, true), 'delete list includes center A');
  assertTrue(in_array($centerB, $centerIds, true), 'delete list includes center B');

  $related = GoogleVacationRepository::findProcessedEventsRelatedToGoogleEvent($doctorId, 'evt-1');
  assertTrue(count($related) === 2, 'event delete uses each tracked center row independently');

  $pdo->prepare('DELETE FROM google_event_vacations WHERE paziresh24_user_id = :id')
      ->execute(['id' => $doctorId]);
  $pdo->prepare('DELETE FROM import_future_vacations_backfill_slots WHERE paziresh24_user_id = :id')
      ->execute(['id' => $doctorId]);
} catch (Throwable $e) {
    if (str_contains($e->getMessage(), 'could not find driver') || str_contains($e->getMessage(), 'Connection')) {
        echo 'SKIP per-center delete DB scenarios (no local database)' . PHP_EOL;
    } else {
        echo 'FAIL per-center delete DB: ' . $e->getMessage() . PHP_EOL;
        $failed++;
    }
}

echo PHP_EOL . '=== UI script parity checks ===' . PHP_EOL;

$script = (string) file_get_contents(dirname(__DIR__, 2) . '/script.js');
assertTrue(
    !preg_match('/vacation-center-item"\)\.forEach\(item =>/s', $script),
    'script.js does not double-toggle vacation center labels'
);
assertTrue(
    str_contains($script, 'selection.mode === "all"') && str_contains($script, 'setVacationCenterSelection({ mode: "selected", centerIds: allIds })'),
    'script.js expands legacy mode=all when centers load'
);

exit($failed > 0 ? 1 : 0);
