<?php

declare(strict_types=1);

final class GoogleWebhookHeaders
{
    /**
     * @return array{channel_id: string, resource_id: string, resource_state: string}
     */
    public static function read(): array
    {
        $headers = [];

        if (function_exists('getallheaders')) {
            $raw = getallheaders();
            if (is_array($raw)) {
                foreach ($raw as $name => $value) {
                    if (is_string($name) && is_string($value)) {
                        $headers[strtolower($name)] = $value;
                    }
                }
            }
        }

        $map = [
            'channel_id' => ['x-goog-channel-id', 'HTTP_X_GOOG_CHANNEL_ID'],
            'resource_id' => ['x-goog-resource-id', 'HTTP_X_GOOG_RESOURCE_ID'],
            'resource_state' => ['x-goog-resource-state', 'HTTP_X_GOOG_RESOURCE_STATE'],
        ];

        $result = [
            'channel_id' => '',
            'resource_id' => '',
            'resource_state' => '',
        ];

        foreach ($map as $key => $sources) {
            foreach ($sources as $source) {
                if (isset($headers[$source])) {
                    $result[$key] = trim($headers[$source]);
                    break;
                }

                if (isset($_SERVER[$source]) && is_string($_SERVER[$source])) {
                    $result[$key] = trim($_SERVER[$source]);
                    break;
                }
            }
        }

        return $result;
    }
}
