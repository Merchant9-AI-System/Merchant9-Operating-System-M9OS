<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * GET /sso/logout?client_id=...&post_logout_redirect_uri=... - single logout: a connected system (Logistic) sends the user
 * here after they log out there, so the m9os session ends too, then returns them to that system. Both parameters are
 * validated against config/sso.php (known client + exact allow-listed return URL), so this can never act as an open redirect.
 * It works whether or not the user is still signed in here.
 */
class SsoLogoutController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $client = collect((array) config('sso.clients', []))->first(
            fn (array $client): bool => filled($client['client_id'] ?? null) && hash_equals((string) $client['client_id'], (string) $request->query('client_id')),
        );

        abort_if($client === null, 400, 'Unknown SSO client.');

        $target = (string) $request->query('post_logout_redirect_uri');

        abort_unless(in_array($target, (array) ($client['post_logout_redirect_uris'] ?? []), true), 400, 'Return URL is not allowed.');

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return redirect()->away($target);
    }
}
