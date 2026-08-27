<?php

namespace App\Filament\Resources\Users\RelationManagers;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Token API (Sanctum) - dipakai klien luar spt Claude.ai Custom Connectors sambung ke
 * Mcp::web('/mcp/inventory', ...) (rujuk routes/ai.php) via header Authorization: Bearer.
 * TIADA CreateAction lalai Filament sengaja - borang generik x sesuai (createToken() Sanctum
 * jana rentetan plaintext SATU KALI sahaja, bukan medan boleh mass-assign terus spt model
 * biasa) - rujuk action "generateToken" tersendiri bawah.
 */
class TokensRelationManager extends RelationManager
{
    protected static string $relationship = 'tokens';

    protected static ?string $title = 'Token API';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->label('Nama'),
                TextColumn::make('last_used_at')->label('Kali Terakhir Digunakan')
                    ->dateTime('d/m/Y H:i')->placeholder('Belum pernah')->sortable(),
                TextColumn::make('created_at')->label('Dijana')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->headerActions([
                Action::make('generateToken')
                    ->label('Jana Token Baharu')
                    ->icon(Heroicon::OutlinedKey)
                    ->color('success')
                    ->schema([
                        TextInput::make('name')->label('Nama Token')->required()
                            ->placeholder('cth. claude-connector')
                            ->helperText('Nama utk kenal pasti kegunaan token ni kemudian (cth. nama sistem/klien yg guna) - bukan rahsia.'),
                    ])
                    ->action(function (array $data) {
                        $token = $this->getOwnerRecord()->createToken($data['name'])->plainTextToken;

                        Notification::make()
                            ->title('Token dijana - salin SEKARANG, tidak boleh dipapar semula selepas ni')
                            ->body($token)
                            ->success()
                            ->persistent()
                            ->send();
                    }),
            ])
            ->recordActions([
                DeleteAction::make()->label('Cabut'),
            ])
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc');
    }
}
