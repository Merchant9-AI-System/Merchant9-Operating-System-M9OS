<?php

namespace App\Console\Commands;

use App\Filament\Pages\JemisysConnectionStatus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Pre-warm cache diagnostik JemisysConnectionStatus (network+auth+query SQL Server sebenar via
 * Tailscale) - semakan LIVE penuh disahkan ~12s (query TblInventory sahaja ~11.5s). Page
 * mount() SENGAJA baca cache SAHAJA (rujuk JemisysConnectionStatus::mount()) - tanpa command
 * berjadual ni, cache tamat tempoh (TTL 300s) & pelawat SETERUSNYA yg buka page tu terkena
 * semakan LIVE penuh, punca 504 Gateway Timeout disahkan production.
 */
#[Signature('app:warm-jemisys-diagnostics')]
#[Description('Pre-warm cache diagnostik sambungan JEMiSys - jalankan berjadual (rujuk routes/console.php) elak 504 pd JemisysConnectionStatus.')]
class WarmJemisysDiagnostics extends Command
{
    public function handle(): int
    {
        $start = microtime(true);
        $page = new JemisysConnectionStatus;

        // Panggil ketiga-tiga (bukan cuma runDiagnostics()) - getMirrorStatusProperty()/
        // getMerchantNicknameStatusProperty() masing2 ada cache TTL 60s SENDIRI (rujuk
        // dokblok kaedah tsb) yg juga perlu terus segar, bukan cuma "checks" (network/auth/query).
        $page->runDiagnostics();
        $page->getMirrorStatusProperty();
        $page->getMerchantNicknameStatusProperty();

        $ms = round((microtime(true) - $start) * 1000);
        $this->info("Diagnostik JEMiSys di-cache semula ({$ms}ms).");

        return self::SUCCESS;
    }
}
