<?php

namespace App\Filament\Resources\BranchMonthlyTargets\Schemas;

use App\Models\BranchMonthlyTarget;
use App\Support\BranchActivityCalculator;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class BranchMonthlyTargetForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('store_code')
                    ->label('Cawangan')
                    ->options(fn (): array => collect(BranchActivityCalculator::branches())
                        ->mapWithKeys(fn (string $code): array => [BranchMonthlyTarget::normalizeStore($code) => BranchMonthlyTarget::normalizeStore($code)])
                        ->all())
                    ->required()
                    ->searchable()
                    // Cawangan & bulan ialah kunci rekod - tak diubah semasa edit (ubah = rekod baharu).
                    ->disabled(fn (?Model $record): bool => $record !== null),
                Select::make('month')
                    ->label('Bulan')
                    ->options(function (?Model $record): array {
                        $options = BranchMonthlyTarget::monthOptions();

                        // Rekod sedia ada di luar julat pilihan (bulan lama) tetap boleh dipaparkan.
                        if ($record !== null) {
                            $options[$record->month->toDateString()] ??= $record->month->locale('ms')->translatedFormat('F Y');
                        }

                        return $options;
                    })
                    ->default(fn (): string => BranchMonthlyTarget::currentMonth()->toDateString())
                    // Atribut `month` ialah Carbon (cast date) - kunci pilihan ialah 'Y-m-d', maka diseragamkan.
                    ->formatStateUsing(fn (mixed $state): ?string => filled($state) ? Carbon::parse($state)->toDateString() : null)
                    ->required()
                    ->disabled(fn (?Model $record): bool => $record !== null)
                    ->rules([
                        fn (Get $get, ?Model $record): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($get, $record): void {
                            if ($record === null && filled($get('store_code')) && filled($value)
                                && BranchMonthlyTarget::findFor((string) $get('store_code'), Carbon::parse($value)) !== null) {
                                $fail('Sasaran untuk cawangan dan bulan ini sudah wujud - ubah rekod sedia ada.');
                            }
                        },
                    ]),
                TextInput::make('target_rm')
                    ->label('Sasaran jualan (RM)')
                    ->numeric()
                    ->prefix('RM')
                    ->minValue(0)
                    ->maxValue(999999999)
                    ->required(),
                TextInput::make('target_grams')
                    ->label('Sasaran berat (gram)')
                    ->numeric()
                    ->suffix('g')
                    ->minValue(0)
                    ->maxValue(9999999)
                    ->required(),
                Textarea::make('remark')
                    ->label('Catatan')
                    ->helperText('Pilihan untuk HQ. Ketua cawangan wajib mengisi catatan bila mengemas kini sasaran yang sudah ada.')
                    ->maxLength(500)
                    ->columnSpanFull(),
            ]);
    }
}
