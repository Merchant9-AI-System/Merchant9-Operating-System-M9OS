<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * Dijalankan di background lepas JemisysDataLoader import berjaya - elak proses
 * cache:clear + app:warm-dashboard-cache (~30-45 saat, 13 widget) berlaku dlm request
 * HTTP upload yg sama, yg dah pun panjang (import boleh cecah minit utk fail besar).
 * Sambung request lama ni dgn kerja background lagi boleh langgar had timeout
 * Nginx/PHP-FPM di server production.
 */
class WarmDashboardCacheJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Cache::flush();

        Artisan::call('app:warm-dashboard-cache');
    }
}
