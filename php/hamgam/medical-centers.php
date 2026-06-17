<?php

declare(strict_types=1);

/**
 * POST /hamgam/medical-centers — لیست مراکز درمانی برای UI انتخاب مرخصی
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/vacation-bootstrap.php';

hamgam_load_vacation_modules();

Request::applyCors();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    Response::jsonError('Method not allowed', 405);
}

try {
    $accessToken = Request::accessToken();
    if ($accessToken === '') {
        Response::jsonError('Unauthorized', 401);
    }

    $raw = Paziresh24VacationApi::listMedicalCenters($accessToken);
    if ($raw === null) {
        Response::jsonError('Failed to fetch medical centers', 502);
    }

    $centers = [];
    $seenIds = [];
    foreach ($raw as $row) {
        if (!is_array($row)) {
            continue;
        }

        $item = Paziresh24VacationApi::normalizeMedicalCenterRow($row);
        if ($item === null) {
            continue;
        }

        $centerId = $item['medical_center_id'];
        if (isset($seenIds[$centerId])) {
            continue;
        }

        $seenIds[$centerId] = true;
        $centers[] = $item;
    }

    if ($raw !== [] && $centers === []) {
        RequestContext::log('hamgam/medical-centers', 'API rows received but none could be normalized, count=' . count($raw));
    }

    Response::json([
        'ok' => true,
        'centers' => $centers,
    ]);
} catch (Throwable $e) {
    RequestContext::log('hamgam/medical-centers', $e->getMessage());
    Response::jsonError('Internal server error', 500);
}
