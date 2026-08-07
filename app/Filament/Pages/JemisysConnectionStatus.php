<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\StatusConnectionWidget;
use App\Jobs\SyncJemisysMirrors;
use App\Jobs\SyncMerchantNicknamesAndImages;
use App\Models\InventoryMirror;
use App\Models\Jemisys\Category;
use App\Models\Jemisys\Store;
use App\Models\Jemisys\Vendor;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Diagnostik sambungan 'jemisys' (SQL Server via Tailscale) - jalankan semak berperingkat
 * (network -> driver PHP -> auth -> query sebenar) spt yg dibuat manual sepanjang setup awal,
 * supaya troubleshooting lepas ni tak perlu SSH masuk & jalankan sqlcmd manual setiap kali.
 */
class JemisysConnectionStatus extends Page
{
    use HasPageShield;

    protected string $view = 'filament.pages.jemisys-connection-status';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static ?string $navigationLabel = 'Connection Status';

    protected static string|\UnitEnum|null $navigationGroup = 'Data Management';

    protected static ?int $navigationSort = 99;

    /** Cache key sama diguna oleh App\Console\Commands\WarmJemisysDiagnostics (cron warm-ahead)
     * & action "Refresh" (bypass, tulis balik segera) - rujuk dokblok runDiagnostics() bawah. */
    public const CACHE_KEY_CHECKS = 'jemisys_connection_diagnostics_checks';

    public const CACHE_TTL_SECONDS = 300;

    /** @var array<string, array{label: string, status: string, detail: string, ms: ?float}> */
    public array $checks = [];

    public function getSubheading(): ?string
    {
        return __('Diagnostik sambungan "jemisys" (SQL Server via Tailscale) - jalankan semak berperingkat');
    }

    /**
     * mount() SENGAJA baca cache SAHAJA (bukan panggil computeDiagnostics() terus) - semakan
     * penuh (network+auth+query SQL Server sebenar via Tailscale) ambil ~12s (disahkan
     * production - punca 504 Gateway Timeout bila jalan LIVE setiap kali page dibuka). Cache
     * diisi oleh App\Console\Commands\WarmJemisysDiagnostics (scheduled, rujuk routes/console.php)
     * & action "Refresh" bawah - page load sendiri kekal instant tanpa mengira keadaan sambungan.
     */
    public function mount(): void
    {
        $this->checks = Cache::get(self::CACHE_KEY_CHECKS, []);
    }

    protected function getHeaderWidgets(): array
    {
        return [
            StatusConnectionWidget::class,
        ];
    }

    /** @return array{checks: array, mirrors: array<string, int>, lastSyncedAt: ?string} */
    public function getWidgetData(): array
    {
        return [
            'checks' => $this->checks,
            'mirrors' => $this->mirrorStatus['mirrors'],
            'lastSyncedAt' => $this->mirrorStatus['lastSyncedAt'],
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncMirrors')
                ->label('Segerak Data JEMiSys')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('warning')
                ->disabled(fn () => Cache::has(SyncJemisysMirrors::CACHE_KEY_SYNCING) || ($this->checks['network']['status'] ?? null) !== 'ok')
                ->tooltip(fn () => ($this->checks['network']['status'] ?? null) !== 'ok'
                    ? 'Sambungan rangkaian ke JEMiSys gagal - semak Tailscale/VPN (laptop sumber perlu ON & disambung) sebelum segerak.'
                    : null)
                ->requiresConfirmation()
                ->modalDescription('Segerak Category/Vendor/Store/TblInventory drpd SQL Server VPN ke cermin tempatan. Berjalan di latar belakang - ianya mengambil masa beberapa minit.')
                ->action(function () {
                    SyncJemisysMirrors::dispatch();
                    Notification::make()->info()->title('Penyegerakan dimulakan di latar belakang...')->send();
                }),
            Action::make('syncMerchantNicknames')
                ->label('Segerak Nickname & Imej Merchant9')
                ->icon(Heroicon::OutlinedPhoto)
                ->color('warning')
                ->disabled(fn () => Cache::has(SyncMerchantNicknamesAndImages::CACHE_KEY_SYNCING) || Cache::has(SyncJemisysMirrors::CACHE_KEY_SYNCING))
                ->tooltip(fn () => Cache::has(SyncJemisysMirrors::CACHE_KEY_SYNCING)
                    ? 'Sync JEMiSys utama sedang berjalan - tunggu selesai dahulu.'
                    : null)
                ->requiresConfirmation()
                ->modalDescription('Cari nickname & imej produk drpd merchant9.com utk setiap InternalCode yg belum diisi. SANGAT LAMA (boleh berjam-jam pd kali pertama - satu HTTP request + jeda ~200ms setiap design unik). Berjalan di latar belakang, TIDAK menyekat sync JEMiSys utama.')
                ->action(function () {
                    SyncMerchantNicknamesAndImages::dispatch();
                    Notification::make()->info()->title('Penyegerakan nickname/imej dimulakan di latar belakang...')->send();
                }),
            Action::make('refresh')
                ->label('Refresh')
                ->icon(Heroicon::OutlinedArrowPath)
                ->action(function () {
                    $this->runDiagnostics();
                    Notification::make()->success()->title('Diagnostik selesai')->send();
                }),
        ];
    }

    /**
     * @return array{syncing: bool, syncStartedAt: ?string, lastSyncedAt: ?string, mirrors: array<string, int>}
     */
    public function getMirrorStatusProperty(): array
    {
        $startedAt = Cache::get(SyncJemisysMirrors::CACHE_KEY_SYNCING);

        return [
            'syncing' => $startedAt !== null,
            'syncStartedAt' => $startedAt instanceof Carbon ? $startedAt->toIso8601String() : null,
            // Cache (TTL sama dgn CACHE_KEY_CHECKS, diwarmkan bersama via WarmJemisysDiagnostics)
            // - lajur ni dibaca via wire:poll.3s (rujuk blade view). MAX(synced_at) disahkan
            // ~9.6s SEBELUM index ditambah (rujuk migration add_synced_at_indexes_...) - kekal
            // dicache sbg pertahanan tambahan (server production sibuk boleh perlahankan lagi).
            'lastSyncedAt' => Cache::remember('jemisys_last_synced_at', self::CACHE_TTL_SECONDS, fn () => InventoryMirror::max('synced_at')),
            'mirrors' => Cache::remember('jemisys_mirror_counts', self::CACHE_TTL_SECONDS, fn () => [
                'Category' => Category::count(),
                'Vendor' => Vendor::count(),
                'Store' => Store::count(),
                'Inventory' => InventoryMirror::count(),
            ]),
        ];
    }

    /**
     * @return array{syncing: bool, syncStartedAt: ?string, lastCompletedAt: ?string, missingCount: int, totalDistinctCount: int}
     */
    public function getMerchantNicknameStatusProperty(): array
    {
        $startedAt = Cache::get(SyncMerchantNicknamesAndImages::CACHE_KEY_SYNCING);

        // Cache - sama sebab dgn getMirrorStatusProperty() di atas. missingCount (DISTINCT +
        // WHERE merchant_synced_at IS NULL) disahkan ~9.3s SETIAP panggilan pd 490K baris -
        // tanpa cache ni, wire:poll.3s jalankan query 9+ saat tu setiap 3 saat selagi page dibuka.
        $counts = Cache::remember('jemisys_nickname_status_counts', self::CACHE_TTL_SECONDS, function () {
            $baseQuery = fn () => InventoryMirror::query()
                ->whereNotNull('InternalCode')
                ->where('InternalCode', '!=', '');

            return [
                'missingCount' => $baseQuery()->whereNull('merchant_synced_at')->distinct()->count('InternalCode'),
                'totalDistinctCount' => $baseQuery()->distinct()->count('InternalCode'),
            ];
        });

        return [
            'syncing' => $startedAt !== null,
            'syncStartedAt' => $startedAt instanceof Carbon ? $startedAt->toIso8601String() : null,
            // MAX(merchant_synced_at) disahkan ~9.9s SEBELUM index ditambah (sama rasional dgn
            // 'lastSyncedAt' di getMirrorStatusProperty() atas).
            'lastCompletedAt' => Cache::remember('jemisys_merchant_last_completed_at', self::CACHE_TTL_SECONDS, fn () => InventoryMirror::max('merchant_synced_at')),
            'missingCount' => $counts['missingCount'],
            'totalDistinctCount' => $counts['totalDistinctCount'],
        ];
    }

    /**
     * Jalankan semakan LIVE sebenar (network+auth+query SQL Server, rujuk dokblok mount())
     * & tulis hasil ke cache - dipanggil oleh action "Refresh" (bypass cache, staf tunggu
     * sengaja) & App\Console\Commands\WarmJemisysDiagnostics (scheduled, staf TAK tunggu).
     */
    public function runDiagnostics(): void
    {
        $this->checks = [];

        $this->checks['config'] = $this->checkConfig();
        $this->checks['extensions'] = $this->checkExtensions();
        $this->checks['network'] = $this->checkNetwork();

        // Kalau rangkaian dah gagal (VPN/Tailscale down), auth/query PASTI gagal jugak -
        // langkau terus drpd cuba sambung sqlsrv sebenar, yg boleh ambil masa lama (walaupun
        // login_timeout dah ditetapkan) berbanding fsockopen 3 saat semakan network di atas.
        // Fallback ni elak page/refresh "hang" beberapa saat setiap kali VPN down.
        if ($this->checks['network']['status'] !== 'ok') {
            $skipped = 'Dilangkau - sambungan rangkaian gagal (rujuk semakan "Sambungan Rangkaian" di atas). Semak Tailscale/VPN dahulu.';

            $this->checks['auth'] = ['label' => 'Auth SQL Server', 'status' => 'skip', 'detail' => $skipped, 'ms' => null];
            $this->checks['query'] = ['label' => 'Query Sebenar (TblInventory)', 'status' => 'skip', 'detail' => $skipped, 'ms' => null];

            Cache::put(self::CACHE_KEY_CHECKS, $this->checks, self::CACHE_TTL_SECONDS);

            return;
        }

        $this->checks['auth'] = $this->checkAuth();
        $this->checks['query'] = $this->checkQuery();

        Cache::put(self::CACHE_KEY_CHECKS, $this->checks, self::CACHE_TTL_SECONDS);
    }

    protected function checkConfig(): array
    {
        $config = config('database.connections.jemisys');

        $detail = sprintf(
            'driver=%s host=%s port=%s database=%s username=%s password=%s',
            $config['driver'] ?? '(xde)',
            $config['host'] ?? '(xde)',
            $config['port'] ?? '(xde)',
            $config['database'] ?? '(xde)',
            $config['username'] ?? '(xde)',
            filled($config['password'] ?? null) ? '••••••••' : '(xde)',
        );

        $missing = array_filter([
            'host' => $config['host'] ?? null,
            'database' => $config['database'] ?? null,
            'username' => $config['username'] ?? null,
            'password' => $config['password'] ?? null,
        ], fn ($v) => blank($v));

        return [
            'label' => 'Konfigurasi (.env)',
            'status' => $missing === [] ? 'ok' : 'fail',
            'detail' => $missing === [] ? $detail : $detail.' - HILANG: '.implode(', ', array_keys($missing)),
            'ms' => null,
        ];
    }

    protected function checkExtensions(): array
    {
        $sqlsrv = extension_loaded('sqlsrv');
        $pdoSqlsrv = extension_loaded('pdo_sqlsrv');

        return [
            'label' => 'Extension PHP',
            'status' => ($sqlsrv && $pdoSqlsrv) ? 'ok' : 'fail',
            'detail' => 'sqlsrv='.($sqlsrv ? 'loaded' : 'TAK LOADED').', pdo_sqlsrv='.($pdoSqlsrv ? 'loaded' : 'TAK LOADED'),
            'ms' => null,
        ];
    }

    protected function checkNetwork(): array
    {
        $config = config('database.connections.jemisys');
        $host = $config['host'] ?? null;
        $port = (int) ($config['port'] ?? 1433);

        if (blank($host)) {
            return ['label' => 'Sambungan Rangkaian (TCP)', 'status' => 'skip', 'detail' => 'JEMISYS_HOST xde dlm .env', 'ms' => null];
        }

        $start = microtime(true);
        $socket = @fsockopen($host, $port, $errno, $errstr, 3);
        $ms = round((microtime(true) - $start) * 1000, 1);

        if ($socket === false) {
            return [
                'label' => 'Sambungan Rangkaian (TCP)',
                'status' => 'fail',
                'detail' => "Tak boleh sambung ke {$host}:{$port} - [{$errno}] {$errstr}. Semak Tailscale (tailscale status) & Windows Firewall port {$port}.",
                'ms' => $ms,
            ];
        }

        fclose($socket);

        return [
            'label' => 'Sambungan Rangkaian (TCP)',
            'status' => 'ok',
            'detail' => "Port {$port} kat {$host} boleh dicapai (Tailscale + firewall ok).",
            'ms' => $ms,
        ];
    }

    protected function checkAuth(): array
    {
        $start = microtime(true);

        try {
            DB::purge('jemisys');
            DB::connection('jemisys')->getPdo();
            $ms = round((microtime(true) - $start) * 1000, 1);

            return ['label' => 'Auth SQL Server', 'status' => 'ok', 'detail' => 'Login berjaya.', 'ms' => $ms];
        } catch (Throwable $e) {
            $ms = round((microtime(true) - $start) * 1000, 1);

            return [
                'label' => 'Auth SQL Server',
                'status' => 'fail',
                'detail' => 'Login gagal - '.$e->getMessage(),
                'ms' => $ms,
            ];
        }
    }

    protected function checkQuery(): array
    {
        $start = microtime(true);

        try {
            $result = DB::connection('jemisys')->selectOne('SELECT COUNT(*) AS c FROM [TblInventory]');
            $ms = round((microtime(true) - $start) * 1000, 1);

            return [
                'label' => 'Query Sebenar (TblInventory)',
                'status' => 'ok',
                'detail' => number_format($result->c).' baris.',
                'ms' => $ms,
            ];
        } catch (Throwable $e) {
            $ms = round((microtime(true) - $start) * 1000, 1);

            return [
                'label' => 'Query Sebenar (TblInventory)',
                'status' => 'fail',
                'detail' => $e->getMessage(),
                'ms' => $ms,
            ];
        }
    }
}
