<?php

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
