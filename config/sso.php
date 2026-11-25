<?php

/*
|--------------------------------------------------------------------------
| SSO m9os (pusat log masuk)
|--------------------------------------------------------------------------
|
| m9os ialah pembekal identiti (Passport, OAuth2 authorization code + PKCE) bagi sistem lain (Logistic, dll). Setiap sistem
| ialah satu klien Passport yang dicipta dgn `php artisan sso:client {app}`; ID klien dimasukkan dlm .env (SSO_*_CLIENT_ID)
| supaya skrin kelulusan boleh dilangkau HANYA bagi klien first-party ini (bukan klien lain, cth. MCP yg didaftar secara
| dinamik). Scope `sso` hanya membenarkan membaca /api/sso/userinfo.
|
*/

return [
    'scope' => 'sso',

    'clients' => [
        'logistic' => [
            'name' => 'SSO: Logistic',
            'client_id' => env('SSO_LOGISTIC_CLIENT_ID'),
            // URI panggil balik (mesti tepat sama): tempatan dan production.
            'redirect_uris' => array_values(array_filter(array_map('trim', explode(',', (string) env(
                'SSO_LOGISTIC_REDIRECT_URIS',
                'http://merchant9-logistic.test/auth/m9os/callback,https://logistic.m9is.my/auth/m9os/callback',
            ))))),
            // URL penuh (tepat sama) yg boleh dituju selepas single logout (GET /sso/logout) - halaman log masuk sistem itu.
            'post_logout_redirect_uris' => array_values(array_filter(array_map('trim', explode(',', (string) env(
                'SSO_LOGISTIC_POST_LOGOUT_URIS',
                'http://merchant9-logistic.test/login,https://logistic.m9is.my/login',
            ))))),
        ],
    ],
];
