<?php

namespace App\Console\Commands;

use App\Models\OAuthClient;
use Illuminate\Console\Command;
use Laravel\Passport\ClientRepository;

/**
 * Cipta (atau kemas kini URI panggil balik) klien SSO Passport bagi satu sistem yg disenaraikan dlm config/sso.php.
 * Rahsia klien hanya dipaparkan SEKALI semasa dicipta - salin ke .env sistem itu dan ID klien ke .env m9os
 * (SSO_<NAMA>_CLIENT_ID) supaya skrin kelulusan dilangkau bagi klien first-party ini.
 */
class SsoClientCommand extends Command
{
    protected $signature = 'sso:client {app : Nama sistem dlm config/sso.php (cth. logistic)}';

    protected $description = 'Cipta/kemas kini klien SSO Passport bagi satu sistem (cth. logistic)';

    public function handle(ClientRepository $clients): int
    {
        $app = (string) $this->argument('app');
        $config = config("sso.clients.{$app}");

        if (! is_array($config)) {
            $this->error("Sistem '{$app}' tiada dlm config/sso.php.");

            return self::FAILURE;
        }

        $redirects = $config['redirect_uris'] ?? [];

        if ($redirects === []) {
            $this->error('Tiada redirect URI ditetapkan (SSO_'.strtoupper($app).'_REDIRECT_URIS).');

            return self::FAILURE;
        }

        $existing = filled($config['client_id'] ?? null) ? OAuthClient::query()->find($config['client_id']) : null;

        if ($existing !== null) {
            $existing->forceFill(['redirect_uris' => $redirects, 'revoked' => false])->save();
            $this->info("Klien '{$existing->name}' ({$existing->getKey()}) dikemas kini. URI: ".implode(', ', $redirects));

            return self::SUCCESS;
        }

        $client = $clients->createAuthorizationCodeGrantClient($config['name'] ?? "SSO: {$app}", $redirects, true);

        $this->info('Klien dicipta. Simpan nilai berikut SEKARANG (rahsia tidak dipaparkan lagi):');
        $this->line('  m9os .env     : SSO_'.strtoupper($app).'_CLIENT_ID='.$client->getKey());
        $this->line('  Sistem '.$app.' .env : M9OS_CLIENT_ID='.$client->getKey());
        $this->line('  Sistem '.$app.' .env : M9OS_CLIENT_SECRET='.$client->plainSecret);

        return self::SUCCESS;
    }
}
