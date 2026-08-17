<?php

use App\Jobs\SyncJemisysMirrors;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Refresh-ahead: cache widget/Rearrange TTL 3600s (1 jam) - jalankan setiap 15 minit supaya
// cache SENTIASA segar (margin selamat sebelum tamat tempoh), elak pengguna sesekali terkena
// "cold cache" bertembung dgn lock sementara Windows (rujuk memori projek: bug #9/#11/#12).
// PENTING: perlukan `php artisan schedule:work` (dev) atau cron `schedule:run` (VM production)
// berjalan - definisi ni sahaja TIDAK auto-jalan tanpa salah satu proses tu aktif.
Schedule::command('app:warm-dashboard-cache')->everyFifteenMinutes()->withoutOverlapping();

// JemisysConnectionStatus::mount() SENGAJA baca cache SAHAJA (TTL 300s, rujuk
// JemisysConnectionStatus::CACHE_TTL_SECONDS) - tanpa jadual ni, cache tamat tempoh & pelawat
// SETERUSNYA yg buka page tu terkena semakan LIVE network+auth+query SQL Server penuh (~12s,
// disahkan production - punca 504 Gateway Timeout). Setiap 5 minit imbang antara data segar &
// beban semakan live (network+SQL Server+2 query DISTINCT tempatan, jumlah ~26s setiap jalan).
Schedule::command('app:warm-jemisys-diagnostics')->everyFiveMinutes()->withoutOverlapping();

// Auto-segerak harian (GANTIKAN klik manual butang "Segerak Data JEMiSys" di
// JemisysConnectionStatus - rujuk dokblok SyncJemisysMirrors::handle() utk apa job ni buat).
// 09:00 WAKTU MALAYSIA (->timezone() WAJIB - config('app.timezone') apl ni "UTC", TANPA ni
// dailyAt('09:00') jalan 9pg UTC = 5ptg MYT, silap 8 jam) - lengah ~50 minit lepas SQL Server
// Agent job tempatan "Daily Local Backup JEMiSys_M9 Restore" mula pd 08:10 (laptop sumber
// sambungan 'jemisys' via Tailscale), bagi ruang restore tempatan siap dulu. Kalau laptop
// sumber OFF/Tailscale down hari tsb, job ni GAGAL SENYAP (log + notifikasi Filament ke
// super_admin - rujuk catch() dlm handle()) - tiada apa dipecahkan, cermin cuma kekal stale
// sehingga sumber kembali ONLINE.
Schedule::job(new SyncJemisysMirrors)->dailyAt('09:00')->timezone('Asia/Kuala_Lumpur')->withoutOverlapping();
