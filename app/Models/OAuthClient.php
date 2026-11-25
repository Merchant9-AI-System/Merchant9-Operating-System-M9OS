<?php

namespace App\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Passport\Client;
use Laravel\Passport\Scope;

/**
 * Klien Passport aplikasi ini. Satu-satunya perbezaan drpd Laravel\Passport\Client: klien SSO first-party (ID disenaraikan
 * dlm config sso.clients) melangkau skrin kelulusan - pengguna yg sudah log masuk di m9os terus dibawa kembali ke sistem itu.
 * Pengenalan ikut ID dlm config (BUKAN nama klien) kerana klien MCP didaftar secara dinamik oleh pihak luar dan boleh
 * memilih nama sesuka hati - nama "SSO: ..." tidak boleh dipercayai utk melangkau kelulusan.
 */
class OAuthClient extends Client
{
    /**
     * @param  Scope[]  $scopes
     */
    public function skipsAuthorization(Authenticatable $user, array $scopes): bool
    {
        return self::isFirstPartySso((string) $this->getKey());
    }

    public static function isFirstPartySso(string $clientId): bool
    {
        if ($clientId === '') {
            return false;
        }

        foreach ((array) config('sso.clients', []) as $client) {
            if (($client['client_id'] ?? null) !== null && (string) $client['client_id'] === $clientId) {
                return true;
            }
        }

        return false;
    }
}
