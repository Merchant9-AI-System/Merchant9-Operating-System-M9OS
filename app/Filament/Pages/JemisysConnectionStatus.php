<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\StatusConnectionWidget;
use App\Jobs\SyncJemisysMirrors;
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

    /** @var array<string, array{label: string, status: string, detail: string, ms: ?float}> */
    public array $checks = [];

    public function getSubheading(): ?string
    {
        return __('Diagnostik sambungan "jemisys" (SQL Server via Tailscale) - jalankan semak berperingkat');
    }

    public function mount(): void
    {
        $this->runDiagnostics();
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
            'lastSyncedAt' => InventoryMirror::max('synced_at'),
            'mirrors' => [
                'Category' => Category::count(),
                'Vendor' => Vendor::count(),
                'Store' => Store::count(),
                'Inventory' => InventoryMirror::count(),
            ],
        ];
    }

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

            return;
        }

        $this->checks['auth'] = $this->checkAuth();
        $this->checks['query'] = $this->checkQuery();
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
