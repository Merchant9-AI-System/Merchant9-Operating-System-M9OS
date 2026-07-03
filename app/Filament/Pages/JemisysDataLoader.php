<?php

namespace App\Filament\Pages;

use App\Jobs\WarmDashboardCacheJob;
use App\Support\JemisysSqlLoader;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Ciri sementara: benarkan manager muat naik fail .sql (dump export JEMiSys) utk
 * ganti terus jemisys.db, tanpa perlu jalankan load_data.py secara manual di server.
 */
class JemisysDataLoader extends Page
{
    protected string $view = 'filament.pages.jemisys-data-loader';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static ?string $navigationLabel = 'Muat Naik Data JEMiSys';

    protected static string|\UnitEnum|null $navigationGroup = 'Data Management';

    protected static ?int $navigationSort = 99;

    public static function shouldRegisterNavigation(): bool
    {
        return Auth::user()?->hasRole('manager') ?? false;
    }

    public function mount(): void
    {
        abort_unless(Auth::user()?->hasRole('manager'), 403);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('runImport')
                ->label('Muat Naik & Jalankan')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('Ini akan GANTIKAN data sedia ada dlm jemisys.db. Backup automatik dibuat sebelum proses; kalau import gagal, jemisys.db dikembalikan ke keadaan asal.')
                ->schema([
                    FileUpload::make('sql_file')
                        ->label('Fail .sql')
                        ->disk('local')
                        ->directory('jemisys-imports')
                        ->acceptedFileTypes(['text/plain', 'application/sql', 'application/x-sql', 'application/octet-stream'])
                        ->maxSize(2097152)
                        ->required(),
                ])
                ->action(function (array $data) {
                    $path = Storage::disk('local')->path($data['sql_file']);

                    try {
                        $backup = JemisysSqlLoader::load($path);

                        WarmDashboardCacheJob::dispatch();

                        Notification::make()
                            ->title('Data JEMiSys berjaya dimuat naik')
                            ->body('Backup lama disimpan sbg: '.basename($backup).'. Cache dashboard sedang dikemaskini di background.')
                            ->success()
                            ->send();
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('Import gagal')
                            ->body($e->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();
                    } finally {
                        Storage::disk('local')->delete($data['sql_file']);
                    }
                }),
        ];
    }
}
