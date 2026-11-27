<?php

namespace App\Support\HomeSummary;

use App\Filament\Pages\JemisysConnectionStatus;
use App\Jobs\SyncJemisysMirrors;
use App\Models\RestockSnapshot;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Kesihatan sistem untuk super_admin pada /home: segerak JEMiSys terakhir, snapshot Restock Analysis, job gagal & baris giliran.
 * Semua kad dikira daripada jadual tempatan yang kecil (mirror kedai, restock_snapshots, jobs, failed_jobs) - tiada panggilan
 * ke JEMiSys. Satu kad gagal -> hanya kad itu papar "tidak tersedia".
 *
 * Hanya super_admin boleh memasang modul ini (HomeModules `roles`), supaya maklumat teknikal tak bocor ke peranan lain.
 */
class SystemHealthSection implements SummarySection
{
    public const ROLE = 'super_admin';

    /** Segerak & snapshot dijalankan setiap hari; lebih daripada ini = terlepas satu larian (amaran), 2x ganda = merah. */
    public const DAILY_GRACE_HOURS = 26;

    /** Jumlah job gagal terkini yang disenaraikan. */
    public const FAILED_LIMIT = 5;

    /** Baris giliran tertua melebihi ini (minit) = amaran; 2 jam = merah. */
    public const QUEUE_WARN_MINUTES = 15;

    public function key(): string
    {
        return 'system_health';
    }

    public function title(): string
    {
        return 'Kesihatan Sistem';
    }

    public function appliesTo(User $user): bool
    {
        return $user->hasRole(self::ROLE);
    }

    public function build(User $user, Carbon $date): array
    {
        $items = [
            self::safely('sync', 'Segerak JEMiSys', fn () => self::syncItem()),
            self::safely('snapshot', 'Snapshot Restock', fn () => self::snapshotItem()),
            self::safely('failed', 'Job gagal', fn () => self::failedItem()),
            self::safely('queue', 'Baris giliran', fn () => self::queueItem()),
        ];

        return ['items' => $items, 'list' => self::list()];
    }

    /** @return array<string, mixed> */
    private static function syncItem(): array
    {
        if (Cache::has(SyncJemisysMirrors::CACHE_KEY_SYNCING)) {
            return ['key' => 'sync', 'label' => 'Segerak JEMiSys', 'value' => 'Sedang berjalan', 'hint' => 'Segerak mirror bermula '.self::ago(Cache::get(SyncJemisysMirrors::CACHE_KEY_SYNCING)).'.', 'tone' => 'default'];
        }

        // Jadual cermin kedai kecil (puluhan baris) dan disegerak dalam larian yang sama dgn inventori - MAX() inventori (500k+ baris) terlalu berat.
        $last = DB::table('jemisys_store_mirror')->max('synced_at');

        return self::freshnessItem('sync', 'Segerak JEMiSys', $last, 'Dijadualkan setiap hari 08:40.', 'Belum pernah disegerak');
    }

    /** @return array<string, mixed> */
    private static function snapshotItem(): array
    {
        $last = RestockSnapshot::query()->max('computed_at');

        return self::freshnessItem('snapshot', 'Snapshot Restock', $last, 'Dibina selepas segerak. Perlu dibina manual jika belum: php artisan app:build-restock-snapshot.', 'Belum dibina');
    }

    /** @return array<string, mixed> */
    private static function failedItem(): array
    {
        $total = DB::table('failed_jobs')->count();
        $recent = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDays(7))->count();
        $today = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();
        $latest = DB::table('failed_jobs')->max('failed_at');

        return [
            'key' => 'failed',
            'label' => 'Job gagal',
            'value' => $recent === 0 ? 'Tiada' : $recent.' gagal',
            'hint' => ($recent === 0 ? 'Tiada job gagal dalam 7 hari terakhir.' : 'Dalam 7 hari terakhir.').($total === 0 ? '' : ' Jumlah '.$total.' · terkini '.self::ago($latest).'.'),
            'tone' => match (true) {
                $today > 0 => 'bad',
                $recent > 0 => 'warn',
                default => 'good',
            },
        ];
    }

    /** @return array<string, mixed> */
    private static function queueItem(): array
    {
        $waiting = DB::table('jobs')->count();

        if ($waiting === 0) {
            return ['key' => 'queue', 'label' => 'Baris giliran', 'value' => 'Kosong', 'hint' => 'Tiada job menunggu.', 'tone' => 'good'];
        }

        $oldest = Carbon::createFromTimestamp((int) DB::table('jobs')->min('created_at'));
        $minutes = (int) $oldest->diffInMinutes(now());

        return [
            'key' => 'queue',
            'label' => 'Baris giliran',
            'value' => $waiting.' menunggu',
            'hint' => 'Tertua '.self::ago($oldest).($minutes >= self::QUEUE_WARN_MINUTES ? ' - pekerja giliran (queue worker) mungkin tidak berjalan.' : '.'),
            'tone' => match (true) {
                $minutes >= 120 => 'bad',
                $minutes >= self::QUEUE_WARN_MINUTES => 'warn',
                default => 'good',
            },
        ];
    }

    /**
     * Job gagal terkini + pautan ke diagnostik sambungan.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function list(): array
    {
        $list = [];

        try {
            $list = DB::table('failed_jobs')
                ->orderByDesc('id')
                ->limit(self::FAILED_LIMIT)
                ->get(['payload', 'exception', 'failed_at'])
                ->map(function ($row) {
                    $name = (string) (json_decode((string) $row->payload, true)['displayName'] ?? 'Job');
                    $firstLine = trim((string) strtok((string) $row->exception, "\n"));

                    return [
                        'tag' => class_basename($name),
                        'text' => self::ago($row->failed_at).' · '.mb_strimwidth($firstLine, 0, 140, '...'),
                        'tone' => Carbon::parse($row->failed_at)->gte(now()->subDays(7)) ? 'bad' : 'warn',
                        'href' => null,
                    ];
                })
                ->all();
        } catch (\Throwable $e) {
            report($e);
        }

        try {
            $list[] = ['tag' => 'Diagnostik', 'text' => 'Semak sambungan JEMiSys & FINM9 (halaman diagnostik)', 'tone' => 'default', 'href' => JemisysConnectionStatus::getUrl()];
        } catch (\Throwable) {
            // Pautan diagnostik tak tersedia (cth. panel tak dimuatkan) - senarai job gagal tetap dipaparkan.
        }

        return $list;
    }

    /**
     * Kad kesegaran: nilai = berapa lama dahulu; hijau dalam tempoh harian, kuning terlepas satu larian, merah lebih lama.
     *
     * @return array<string, mixed>
     */
    private static function freshnessItem(string $key, string $label, mixed $last, string $hint, string $empty): array
    {
        if ($last === null) {
            return ['key' => $key, 'label' => $label, 'value' => $empty, 'hint' => $hint, 'tone' => 'bad'];
        }

        $at = Carbon::parse($last);
        $hours = $at->diffInHours(now());

        return [
            'key' => $key,
            'label' => $label,
            'value' => self::ago($at),
            'hint' => $at->locale('ms')->translatedFormat('j M Y, H:i').'. '.$hint,
            'tone' => match (true) {
                $hours >= self::DAILY_GRACE_HOURS * 2 => 'bad',
                $hours >= self::DAILY_GRACE_HOURS => 'warn',
                default => 'good',
            },
        ];
    }

    private static function ago(mixed $time): string
    {
        return Carbon::parse($time)->locale('ms')->diffForHumans();
    }

    /**
     * Satu kad gagal -> hanya kad itu "tidak tersedia".
     *
     * @param  \Closure(): array<string, mixed>  $build
     * @return array<string, mixed>
     */
    private static function safely(string $key, string $label, \Closure $build): array
    {
        try {
            return $build();
        } catch (\Throwable $e) {
            report($e);

            return ['key' => $key, 'label' => $label, 'value' => 'Data tidak tersedia', 'hint' => null, 'tone' => 'warn'];
        }
    }
}
