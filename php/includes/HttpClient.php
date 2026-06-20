<?php



declare(strict_types=1);



final class HttpClient

{

    private const TIMEOUT = 30;



    /**

     * @param array<string, string> $headers

     * @param array<string, mixed>|null $body

     * @return array{status:int, body:?array<string, mixed>, raw:string}

     */

    public static function request(

        string $method,

        string $url,

        array $headers = [],

        ?array $body = null,

        string $bodyFormat = 'form',

        ?string $cookieJarPath = null

    ): array {

        $method = strtoupper($method);

        $maxRetries = max(0, (int) (Config::get('HTTP_RETRY_MAX', '2') ?? '2'));

        $retryableMethods = ['GET', 'DELETE'];

        $retryableStatuses = [502, 503, 504];

        $backoffMs = [500, 1000];

        $canRetry = in_array($method, $retryableMethods, true);



        $attempt = 0;



        while (true) {

            $result = self::executeRequest($method, $url, $headers, $body, $bodyFormat, $cookieJarPath);

            $raw = $result['raw'];

            $status = $result['status'];

            $curlError = $result['curl_error'];



            $shouldRetry = false;

            if ($canRetry && $attempt < $maxRetries) {

                if ($raw === false) {

                    $shouldRetry = true;

                } elseif (in_array($status, $retryableStatuses, true)) {

                    $shouldRetry = true;

                }

            }



            if ($shouldRetry) {

                $delayMs = $backoffMs[$attempt] ?? 1000;

                RequestContext::log(

                    'http-retry',

                    $method . ' ' . $url . ' attempt=' . ($attempt + 1)

                    . ' status=' . ($raw === false ? 'curl_error' : (string) $status)

                    . ' delay_ms=' . $delayMs

                    . ($curlError !== '' ? ' error=' . $curlError : '')

                );

                usleep($delayMs * 1000);

                $attempt++;

                continue;

            }



            if ($raw === false) {

                throw new RuntimeException('HTTP request failed: ' . $curlError);

            }



            return [

                'status' => $status,

                'body' => $result['body'],

                'raw' => $raw,

            ];

        }

    }



    /**

     * @param array<string, string> $headers

     * @param array<string, mixed>|null $body

     * @return array{status:int, body:?array<string, mixed>, raw:string|false, curl_error:string}

     */

    private static function executeRequest(

        string $method,

        string $url,

        array $headers = [],

        ?array $body = null,

        string $bodyFormat = 'form',

        ?string $cookieJarPath = null

    ): array {

        $ch = curl_init($url);

        if ($ch === false) {

            throw new RuntimeException('Failed to initialize HTTP client');

        }



        $curlHeaders = [];



        foreach ($headers as $name => $value) {

            $curlHeaders[] = $name . ': ' . $value;

        }



        $sslVerify = Config::getBool('HTTP_SSL_VERIFY', true);



        curl_setopt_array($ch, [

            CURLOPT_RETURNTRANSFER => true,

            CURLOPT_FOLLOWLOCATION => false,

            CURLOPT_TIMEOUT => self::TIMEOUT,

            CURLOPT_CONNECTTIMEOUT => 10,

            CURLOPT_CUSTOMREQUEST => $method,

            CURLOPT_HTTPHEADER => $curlHeaders,

            CURLOPT_SSL_VERIFYPEER => $sslVerify,

            CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,

        ]);

        if ($cookieJarPath !== null && $cookieJarPath !== '') {
            $cookieDir = dirname($cookieJarPath);
            if (!is_dir($cookieDir)) {
                mkdir($cookieDir, 0750, true);
            }

            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJarPath);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJarPath);
        }



        if ($body !== null && in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {

            if ($bodyFormat === 'json') {

                $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                if ($payload === false) {

                    throw new RuntimeException('Failed to encode JSON request body');

                }



                $hasContentType = false;

                foreach ($curlHeaders as $header) {

                    if (stripos($header, 'Content-Type:') === 0) {

                        $hasContentType = true;

                        break;

                    }

                }



                if (!$hasContentType) {

                    curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($curlHeaders, [

                        'Content-Type: application/json',

                    ]));

                }



                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

            } else {

                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($body));

            }

        }



        $raw = curl_exec($ch);

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $error = curl_error($ch);

        curl_close($ch);



        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        $parsed = is_array($decoded) ? $decoded : null;



        return [

            'status' => $status,

            'body' => $parsed,

            'raw' => $raw,

            'curl_error' => $error,

        ];

    }

}


