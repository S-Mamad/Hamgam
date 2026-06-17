<?php



declare(strict_types=1);



/**

 * شروع جریان تغییر حساب Google بدون حذف اتصال فعلی.

 * فقط پس از تکمیل OAuth در google-oauth.php توکن و ایمیل به‌روز می‌شود.

 */



require_once __DIR__ . '/../includes/bootstrap.php';



Request::applyCors();



if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {

    Response::jsonError('Method not allowed', 405);

}



try {

    $accessToken = Request::accessToken();

    if ($accessToken === '') {

        Response::jsonError('Unauthorized', 401);

    }



    $userId = Paziresh24Api::extractUserIdFromJwt($accessToken);

    if ($userId === null) {

        $userId = Paziresh24Api::resolveUserId($accessToken);

    }

    if ($userId === null) {

        Response::jsonError('User not found', 404);

    }



    $tokenRow = GoogleTokensRepository::findByUserId($userId);

    if (!GoogleTokensRepository::hasRefreshToken($tokenRow)) {

        Response::jsonError('Google account not connected', 400);

    }



    $body = Request::jsonBody();

    $returnTo = is_array($body) ? ($body['return_to'] ?? 'launcher') : 'launcher';

    if (!is_string($returnTo) || !in_array($returnTo, ['launcher', 'settings'], true)) {

        $returnTo = 'launcher';

    }



    GoogleTokensRepository::updateHamdastAccessToken($userId, $accessToken);

    Response::json([
        'oauth_url' => Paziresh24Api::buildGoogleOAuthUrl(
            $accessToken,
            $returnTo,
            'change_gmail',
            $userId,
            null
        ),
    ]);

} catch (Throwable $e) {

    RequestContext::log('hamgam/change-gmail', $e->getMessage());

    Response::jsonError('Internal server error', 500);

}

