<?php

declare(strict_types=1);

/**
 * Self-test for Svix webhook verification (no HTTP server required).
 * Run: php php/tools/test-webhook-verifier.php
 */

require_once __DIR__ . '/../includes/Config.php';
require_once __DIR__ . '/../includes/WebhookVerifier.php';

$secret = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';
$secretKey = base64_decode(substr($secret, 6), true);

$msgId = 'msg_test_hamgam_001';
$timestamp = (string) time();
$payload = '{"event":"provider.appointment","data":{"book_id":"test"}}';

$signedContent = $msgId . '.' . $timestamp . '.' . $payload;
$signature = 'v1,' . base64_encode(hash_hmac('sha256', $signedContent, $secretKey, true));

$_SERVER['HTTP_SVIX_ID'] = $msgId;
$_SERVER['HTTP_SVIX_TIMESTAMP'] = $timestamp;
$_SERVER['HTTP_SVIX_SIGNATURE'] = $signature;

$reflection = new ReflectionClass(Config::class);
$prop = $reflection->getProperty('values');
$prop->setAccessible(true);
$prop->setValue(null, ['PAZIRESH24_WEBHOOK_SECRET' => $secret]);

$ok = WebhookVerifier::verifySvix($payload);
if (!$ok) {
    fwrite(STDERR, "FAIL: valid signature rejected\n");
    exit(1);
}

$_SERVER['HTTP_SVIX_SIGNATURE'] = 'v1,invalidsignature000000000000000000000000000=';
$bad = WebhookVerifier::verifySvix($payload);
if ($bad) {
    fwrite(STDERR, "FAIL: invalid signature accepted\n");
    exit(1);
}

unset($_SERVER['HTTP_SVIX_ID'], $_SERVER['HTTP_SVIX_TIMESTAMP'], $_SERVER['HTTP_SVIX_SIGNATURE']);
$prop->setValue(null, []);

$noSecret = WebhookVerifier::verifySvix($payload);
if (!$noSecret) {
    fwrite(STDERR, "FAIL: empty secret should skip verification\n");
    exit(1);
}

echo "OK: WebhookVerifier tests passed\n";
