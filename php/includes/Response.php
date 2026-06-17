<?php



declare(strict_types=1);



final class Response

{

    /**

     * @param array<string, mixed> $data

     */

    public static function json(array $data, int $status = 200): never

    {

        while (ob_get_level() > 0) {

            ob_end_clean();

        }



        http_response_code($status);

        header('Content-Type: application/json; charset=utf-8');

        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        exit;

    }



    public static function jsonError(string $message, int $status = 400): never

    {

        self::json(['error' => $message], $status);

    }



    /**

     * Send JSON to the client, then run heavy work (e.g. calendar backfill) without blocking the response.

     *

     * @param array<string, mixed> $data

     */

    public static function jsonThenContinue(array $data, ?callable $afterResponse = null, int $status = 200): never

    {

        while (ob_get_level() > 0) {

            ob_end_clean();

        }



        ignore_user_abort(true);

        set_time_limit(300);



        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!is_string($json)) {

            $json = '{"ok":false}';

        }



        http_response_code($status);

        header('Content-Type: application/json; charset=utf-8');

        header('Cache-Control: no-store');

        header('Connection: close');

        header('Content-Length: ' . (string) strlen($json));

        echo $json;



        self::releaseClientConnection();



        if ($afterResponse !== null) {

            try {

                $afterResponse();

            } catch (Throwable $e) {

                error_log('[Response] jsonThenContinue callback failed: ' . $e->getMessage());

            }

        }



        exit;

    }



    public static function text(string $body, int $status = 200): never

    {

        while (ob_get_level() > 0) {

            ob_end_clean();

        }



        http_response_code($status);

        header('Content-Type: text/plain; charset=utf-8');

        echo $body;

        exit;

    }



    public static function redirect(string $url): never

    {

        self::redirectFast($url);

    }



    /**

     * HTML redirect — completes faster than a bare 302 on hosts that keep the PHP worker open.

     */

    public static function redirectFast(string $url): never

    {

        while (ob_get_level() > 0) {

            ob_end_clean();

        }



        if (!self::isSafeRedirectUrl($url)) {

            http_response_code(500);

            exit('Invalid redirect URL');

        }



        self::sendInstantRedirectHtml($url);

        exit;

    }



    /**
     * Store OAuth result in localStorage (settings iframe origin) then open Paziresh24 launcher.
     *
     * @param array<string, string> $oauthQuery
     */
    public static function redirectViaLauncherBridge(
        string $launcherUrl,
        array $oauthQuery = [],
        string $storageKey = 'hamgam_oauth_error',
        ?callable $afterRedirect = null
    ): never {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (!self::isSafeRedirectUrl($launcherUrl)) {
            http_response_code(500);
            exit('Invalid redirect URL');
        }

        $launcherUrl = self::appendQueryParam($launcherUrl, '_hamgam', (string) time());

        if ($storageKey === '' || !preg_match('/^hamgam_[a-z0-9_]+$/', $storageKey)) {
            http_response_code(500);
            exit('Invalid OAuth bridge storage key');
        }

        ignore_user_abort(true);
        set_time_limit(300);

        $payload = json_encode($oauthQuery, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($payload)) {
            $payload = '{}';
        }

        $escapedLauncher = htmlspecialchars($launcherUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $jsLauncher = json_encode($launcherUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $jsPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $jsStorageKey = json_encode($storageKey, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        if (!is_string($jsLauncher)) {
            $jsLauncher = '""';
        }
        if (!is_string($jsPayload)) {
            $jsPayload = '""';
        }
        if (!is_string($jsStorageKey)) {
            $jsStorageKey = '""';
        }

        $html = '<!DOCTYPE html><html lang="fa"><head><meta charset="utf-8">'
            . '<title>در حال بازگشت…</title>'
            . '<script>'
            . 'try{localStorage.setItem(' . $jsStorageKey . ',' . $jsPayload . ');}catch(e){}'
            . 'location.replace(' . $jsLauncher . ');'
            . '</script>'
            . '</head><body><p><a href="' . $escapedLauncher . '">بازگشت به پذیرش۲۴</a></p></body></html>';

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Connection: close');
        header('Content-Length: ' . (string) strlen($html));
        echo $html;

        self::releaseClientConnection();

        if ($afterRedirect !== null) {
            try {
                $afterRedirect();
            } catch (Throwable $e) {
                error_log('[Response] redirectViaLauncherBridge callback failed: ' . $e->getMessage());
            }
        }

        exit;
    }



    /**

     * Redirect immediately, then run cleanup (e.g. external API calls) after the client disconnects.

     */

    public static function redirectThenContinue(string $url, ?callable $afterRedirect = null): never

    {

        while (ob_get_level() > 0) {

            ob_end_clean();

        }



        if (!self::isSafeRedirectUrl($url)) {

            http_response_code(500);

            exit('Invalid redirect URL');

        }



        ignore_user_abort(true);

        set_time_limit(300);



        self::sendInstantRedirectHtml($url);

        self::releaseClientConnection();



        if ($afterRedirect !== null) {

            try {

                $afterRedirect();

            } catch (Throwable $e) {

                error_log('[Response] after redirect failed: ' . $e->getMessage());

            }

        }



        exit;

    }



    public static function errorRedirect(): never

    {

        self::redirect(Config::get('REDIRECT_HOME', 'https://www.paziresh24.com/'));

    }



    /**

     * HTML redirect completes faster than a bare 302 on hosts where the PHP worker keeps the socket open.

     */

    private static function sendInstantRedirectHtml(string $url): void

    {

        $escaped = htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $jsUrl = json_encode($url, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        if (!is_string($jsUrl)) {

            $jsUrl = '""';

        }



        $html = '<!DOCTYPE html><html lang="fa"><head><meta charset="utf-8">'

            . '<meta http-equiv="refresh" content="0;url=' . $escaped . '">'

            . '<title>در حال انتقال…</title>'

            . '<script>location.replace(' . $jsUrl . ');</script>'

            . '</head><body><p><a href="' . $escaped . '">ادامه</a></p></body></html>';



        http_response_code(200);

        header('Content-Type: text/html; charset=utf-8');

        header('Cache-Control: no-store, no-cache, must-revalidate');

        header('Connection: close');

        header('Content-Length: ' . (string) strlen($html));

        echo $html;

    }



    private static function releaseClientConnection(): void

    {

        if (function_exists('session_write_close')) {

            @session_write_close();

        }



        while (ob_get_level() > 0) {

            @ob_end_flush();

        }

        @flush();



        if (function_exists('fastcgi_finish_request')) {

            fastcgi_finish_request();

            return;

        }



        // Some reverse proxies only flush once a minimum body size is sent.

        echo str_repeat(' ', 1024);

        @flush();

    }



    private static function appendQueryParam(string $url, string $key, string $value): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . rawurlencode($key) . '=' . rawurlencode($value);
    }

    private static function isSafeRedirectUrl(string $url): bool

    {

        if (!filter_var($url, FILTER_VALIDATE_URL)) {

            return false;

        }



        $parts = parse_url($url);

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {

            return false;

        }



        return in_array(strtolower($parts['scheme']), ['https'], true);

    }

}


