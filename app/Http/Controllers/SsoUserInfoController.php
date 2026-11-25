<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Passport\AccessToken;
use Laravel\Passport\Passport;

/**
 * GET /api/sso/userinfo - maklumat akaun m9os bagi pengguna yg memiliki token (scope `sso`), utk sistem lain yg log masuk
 * melalui m9os (SSO). Hanya medan yg diperlukan utk memadankan pengguna dan memetakan akses: id, nama, e-mel, username,
 * kod cawangan dan nama peranan - TIADA kata laluan/token/permission. Token yg hanya berskop `sso` dibatalkan selepas
 * dibaca (token sekali guna) supaya tidak kekal sah sepanjang tempohnya.
 */
class SsoUserInfoController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $scope = (string) config('sso.scope', 'sso');

        abort_unless($user->tokenCan($scope), 403, 'Token tiada skop SSO.');

        $payload = [
            'sub' => (string) $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            'username' => $user->username,
            'store_code' => filled(trim((string) $user->store_code)) ? strtoupper(trim((string) $user->store_code)) : null,
            'roles' => $user->getRoleNames()->values()->all(),
        ];

        $token = $user->currentAccessToken();

        // Token SSO hanya utk satu bacaan: batalkan jika skopnya `sso` sahaja (token lain, cth. MCP, tidak disentuh).
        if ($token instanceof AccessToken && ($token->oauth_scopes ?? null) === [$scope]) {
            Passport::token()->newQuery()->whereKey($token->oauth_access_token_id)->update(['revoked' => true]);
        }

        return response()->json($payload);
    }
}
