<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

MonitorAuth::requireAuth();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    Response::jsonError('Method not allowed', 405);
}

$lines = isset($_GET['lines']) ? max(10, min(2000, (int) $_GET['lines'])) : 200;
$logFile = __DIR__ . '/../../storage/php-errors.log';

if (!is_file($logFile)) {
    Response::json([
        'ok' => true,
        'lines' => [],
        'size_bytes' => 0,
        'path' => 'php/storage/php-errors.log',
    ]);
}

$content = MonitorServiceTail::readLastLines($logFile, $lines);

Response::json([
    'ok' => true,
    'lines' => $content['lines'],
    'size_bytes' => (int) filesize($logFile),
    'truncated' => $content['truncated'],
    'path' => 'php/storage/php-errors.log',
]);

final class MonitorServiceTail
{
    /**
     * @return array{lines: array<int, string>, truncated: bool}
     */
    public static function readLastLines(string $file, int $maxLines): array
    {
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            return ['lines' => [], 'truncated' => false];
        }

        $buffer = '';
        $chunkSize = 4096;
        $lineCount = 0;

        fseek($handle, 0, SEEK_END);
        $pos = ftell($handle);
        if (!is_int($pos)) {
            fclose($handle);

            return ['lines' => [], 'truncated' => false];
        }

        while ($pos > 0 && $lineCount <= $maxLines) {
            $readSize = min($chunkSize, $pos);
            $pos -= $readSize;
            fseek($handle, $pos);
            $chunk = fread($handle, $readSize);
            if (!is_string($chunk)) {
                break;
            }
            $buffer = $chunk . $buffer;
            $lineCount = substr_count($buffer, "\n");
        }

        fclose($handle);

        $lines = explode("\n", trim($buffer));
        $truncated = count($lines) > $maxLines;
        if ($truncated) {
            $lines = array_slice($lines, -$maxLines);
        }

        return ['lines' => array_values($lines), 'truncated' => $truncated];
    }
}
