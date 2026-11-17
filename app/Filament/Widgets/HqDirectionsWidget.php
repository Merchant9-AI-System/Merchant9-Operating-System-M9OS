<?php

namespace App\Filament\Widgets;

use App\Models\HqDirection;
use App\Models\User;
use App\Support\BranchActivityCalculator;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Facades\Auth;

/**
 * Arahan HQ kepada cawangan (M04): HQ/CEO menaip arahan (rich editor) dalam modal, pilih cawangan (atau semua) dan
 * tempoh berkuat kuasa. Selepas dicipta ia terus muncul di sini dan pd /home ketua cawangan sasaran. Jadual
 * menunjukkan kemajuan akuan "Faham" (sudah faham / jumlah cawangan sasaran) dan modal per-cawangan.
 *
 * Kebenaran (Shield): HasWidgetShield menentukan siapa NAMPAK widget; cipta/ubah/padam melalui HqDirectionPolicy
 * (Create/Update/Delete:HqDirection) - jadi nampak widget tidak semestinya boleh menulis arahan.
 */
class HqDirectionsWidget extends TableWidget
{
    use HasWidgetShield;

    protected static ?string $heading = 'Arahan HQ kepada Cawangan';

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    public function table(Table $table): Table
    {
        return $table
            ->description('Arahan yang anda taip terus muncul pada halaman /home ketua cawangan sasaran. Ketua cawangan menanda "Faham"; arahan yang telah diakui kekal kelihatan pada hari akuan sahaja.')
            ->query(HqDirection::query()->with(['branches', 'acknowledgements'])->latest())
            ->columns([
                TextColumn::make('title')->label('Tajuk')->searchable()->limit(45)->weight('medium'),
                TextColumn::make('target')
                    ->label('Cawangan')
                    ->state(fn (HqDirection $record): string => $record->all_branches ? 'Semua cawangan' : $record->branches->pluck('store_code')->sort()->implode(', '))
                    ->wrap(),
                TextColumn::make('period')
                    ->label('Tempoh')
                    ->state(fn (HqDirection $record): string => $record->effective_from->format('d/m/Y').' - '.($record->effective_to?->format('d/m/Y') ?? 'seterusnya')),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(function (HqDirection $record): string {
                        $today = HqDirection::today();

                        return match (true) {
                            $record->effective_from->gt($today) => 'Akan datang',
                            $record->effective_to !== null && $record->effective_to->lt($today) => 'Tamat',
                            default => 'Aktif',
                        };
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Aktif' => 'success',
                        'Akan datang' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('progress')
                    ->label('Faham')
                    ->badge()
                    ->state(function (HqDirection $record): string {
                        $targets = $record->targetStores();
                        $done = $record->acknowledgements->whereIn('store_code', $targets)->count();

                        return $done.' / '.count($targets);
                    })
                    ->color(function (HqDirection $record): string {
                        $targets = $record->targetStores();

                        return $targets !== [] && $record->acknowledgements->whereIn('store_code', $targets)->count() >= count($targets) ? 'success' : 'warning';
                    }),
                TextColumn::make('created_by_name')->label('Oleh'),
                TextColumn::make('created_at')->label('Dicipta')->dateTime('d/m/Y H:i', HqDirection::TIMEZONE)->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                CreateAction::make('create')
                    ->label('Arahan baharu')
                    ->icon(Heroicon::Plus)
                    ->model(HqDirection::class)
                    ->modalHeading('Arahan HQ baharu')
                    ->modalWidth(Width::ThreeExtraLarge)
                    ->schema(self::formSchema())
                    ->fillForm(fn (): array => ['effective_from' => HqDirection::today()->toDateString(), 'all_branches' => false])
                    ->using(function (array $data): HqDirection {
                        /** @var User $author */
                        $author = Auth::user();

                        return HqDirection::createWithBranches($data, $author);
                    })
                    ->successNotificationTitle('Arahan dihantar ke cawangan sasaran')
                    // Aksi dlm widget (bukan Resource) TIDAK disemak policy secara automatik - tanpa ini sesiapa yang
                    // nampak widget boleh cipta. Disemak eksplisit terhadap HqDirectionPolicy (Shield).
                    ->authorize(fn (): bool => Auth::user()?->can('create', HqDirection::class) ?? false),
            ])
            ->recordActions([
                Action::make('acknowledgements')
                    ->label('Akuan')
                    ->icon(Heroicon::OutlinedCheckBadge)
                    ->color('gray')
                    ->modalHeading(fn (HqDirection $record): string => 'Akuan Faham: '.$record->title)
                    ->modalContent(fn (HqDirection $record) => view('filament.widgets.hq-direction-acknowledgements', ['direction' => $record->loadMissing('acknowledgements', 'branches')]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup')
                    ->authorize(fn (HqDirection $record): bool => Auth::user()?->can('view', $record) ?? false),
                EditAction::make()
                    ->model(HqDirection::class)
                    ->modalHeading('Ubah arahan HQ')
                    ->modalWidth(Width::ThreeExtraLarge)
                    ->schema(self::formSchema())
                    ->fillForm(fn (HqDirection $record): array => [
                        'title' => $record->title,
                        'body' => $record->body,
                        'effective_from' => $record->effective_from->toDateString(),
                        'effective_to' => $record->effective_to?->toDateString(),
                        'all_branches' => $record->all_branches,
                        'branches' => $record->branches->pluck('store_code')->all(),
                    ])
                    ->using(function (HqDirection $record, array $data): HqDirection {
                        $record->update([
                            'title' => $data['title'],
                            'body' => $data['body'],
                            'effective_from' => $data['effective_from'],
                            'effective_to' => $data['effective_to'] ?? null,
                            'all_branches' => (bool) ($data['all_branches'] ?? false),
                        ]);
                        $record->syncBranches($record->all_branches ? [] : ($data['branches'] ?? []));

                        return $record;
                    })
                    ->authorize(fn (HqDirection $record): bool => Auth::user()?->can('update', $record) ?? false),
                DeleteAction::make()
                    ->modalHeading('Padam arahan ini?')
                    ->authorize(fn (HqDirection $record): bool => Auth::user()?->can('delete', $record) ?? false),
            ])
            ->emptyStateHeading('Belum ada arahan HQ')
            ->emptyStateDescription('Tekan "Arahan baharu" untuk menghantar arahan kepada cawangan.');
    }

    /**
     * Borang arahan. Toolbar sengaja dihadkan (tiada warna teks/sorotan/lampiran/jadual) - HTML tetap disanitasi
     * semasa dipaparkan, tetapi lebih sedikit ciri = lebih sedikit permukaan serangan.
     *
     * @return array<int, mixed>
     */
    public static function formSchema(): array
    {
        return [
            TextInput::make('title')->label('Tajuk')->required()->maxLength(150)->columnSpanFull(),
            RichEditor::make('body')
                ->label('Arahan')
                ->required()
                ->toolbarButtons([
                    ['bold', 'italic', 'underline', 'link'],
                    ['h2', 'h3'],
                    ['bulletList', 'orderedList'],
                    ['undo', 'redo'],
                ])
                ->columnSpanFull(),
            Toggle::make('all_branches')->label('Semua cawangan')->live()->columnSpanFull(),
            Select::make('branches')
                ->label('Cawangan')
                ->multiple()
                ->options(fn (): array => collect(BranchActivityCalculator::branches())
                    ->mapWithKeys(fn (string $code): array => [HqDirection::normalizeStore($code) => HqDirection::normalizeStore($code)])
                    ->all())
                ->visible(fn (Get $get): bool => ! $get('all_branches'))
                ->required(fn (Get $get): bool => ! $get('all_branches'))
                ->columnSpanFull(),
            DatePicker::make('effective_from')->label('Berkuat kuasa dari')->required(),
            DatePicker::make('effective_to')->label('Hingga (kosong = seterusnya)')->afterOrEqual('effective_from'),
        ];
    }
}
