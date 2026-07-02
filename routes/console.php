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
