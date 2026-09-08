<?php

use App\Jobs\SyncJemisysMirrors;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Refresh-ahead: cache widget/Rearrange TTL 3600s (1 jam) - jalankan SETIAP JAM (padan tepat
// dgn TTL, atas permintaan explisit - kurangkan kekerapan notifikasi Filament WarmDashboardCache
// hantar SETIAP kali jalan). NOTA: ni buang margin selamat asal (dulu 15 minit, 4x lebih kerap
// drpd TTL) - kalau SATU jalanan hourly ni lengah/gagal, cache BOLEH tamat tempoh sebelum
// percubaan seterusnya, pengguna sesekali terkena "cold cache" live (~10-40+ saat, disahkan
// sesi ni) sblm jalanan seterusnya sempat panaskan semula.
// PENTING: perlukan `php artisan schedule:work` (dev) atau cron `schedule:run` (VM production)
// berjalan - definisi ni sahaja TIDAK auto-jalan tanpa salah satu proses tu aktif.
Schedule::command('app:warm-dashboard-cache')->hourly()->withoutOverlapping();

// JemisysConnectionStatus::mount() SENGAJA baca cache SAHAJA (TTL 300s, rujuk
// JemisysConnectionStatus::CACHE_TTL_SECONDS) - tanpa jadual ni, cache tamat tempoh & pelawat
// SETERUSNYA yg buka page tu terkena semakan LIVE network+auth+query SQL Server penuh (~12s,
// disahkan production - punca 504 Gateway Timeout). Setiap 5 minit imbang antara data segar &
// beban semakan live (network+SQL Server+2 query DISTINCT tempatan, jumlah ~26s setiap jalan).
Schedule::command('app:warm-jemisys-diagnostics')->daily()->withoutOverlapping();

// Auto-segerak harian (GANTIKAN klik manual butang "Segerak Data JEMiSys" di
// JemisysConnectionStatus - rujuk dokblok SyncJemisysMirrors::handle() utk apa job ni buat).
// 08:30 WAKTU MALAYSIA (->timezone() WAJIB - config('app.timezone') apl ni "UTC", TANPA ni
// dailyAt('08:30') jalan 8:30pg UTC = 4:30ptg MYT, silap 8 jam) - lengah lepas SQL Server
// Agent job tempatan "Daily Local Backup JEMiSys_M9 Restore" mula pd 08:10 (laptop sumber
// sambungan 'jemisys' via Tailscale), bagi ruang restore tempatan siap dulu. Asalnya 09:00
// (50 minit selepas 08:10), dipendekkan ke 08:30 (20 minit) 2026-09-07 atas permintaan
// explisit - disahkan backup tsb sentiasa siap jauh sebelum 08:30, jadi selamat kurangkan
// jarak. Kalau laptop sumber OFF/Tailscale down hari tsb, job ni GAGAL SENYAP (log +
// notifikasi Filament ke super_admin - rujuk catch() dlm handle()) - tiada apa dipecahkan,
// cermin cuma kekal stale sehingga sumber kembali ONLINE. Disahkan production 18/8/2026 -
// berjaya jalan auto pd 10:00 (jadual lebih lama), Forge scheduler + kod dah disahkan
// berfungsi. Rujuk juga dokblok kelas SyncJemisysMirrors (KETAHANAN BLIP SEMENTARA,
// 2026-09-07) - larian terjadual ni kini auto cuba semula blip sambungan sementara, bukan
// terus gagal & perlukan campur tangan manusia spt sebelum ni.
Schedule::job(new SyncJemisysMirrors)->dailyAt('08:30')->timezone('Asia/Kuala_Lumpur')->withoutOverlapping();
