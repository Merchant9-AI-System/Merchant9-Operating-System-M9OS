<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Cipta (atau guna semula) user servis bukan-interaktif "QMS Kiosk Service" & jana token Sanctum
 * BAHARU berskop 'members:lookup' sahaja - utk QMS kiosk (apps/desti) panggil
 * POST /api/internal/members/lookup (rujuk routes/api.php). Kata laluan dijana rawak & TIDAK
 * dipaparkan/disimpan plaintext - user ni bukan utk log masuk panel, cuma pemilik token.
 *
 * Jalankan semula bila-bila utk reissue (token BAHARU dijana setiap kali, token lama sedia ada
 * TIDAK dibatalkan - padam manual via panel Filament/TokensRelationManager jika perlu).
 */
#[Signature('app:issue-qms-kiosk-token')]
#[Description('Cipta user servis QMS Kiosk (jika belum wujud) & jana token Sanctum baharu berskop members:lookup.')]
class IssueQmsKioskToken extends Command
{
    public const ABILITY = 'members:lookup';

    public const SERVICE_EMAIL = 'qms-kiosk@service.m9os.internal';

    public function handle(): int
    {
        $user = User::firstOrCreate(
            ['email' => self::SERVICE_EMAIL],
            [
                'name' => 'QMS Kiosk Service',
                'password' => Hash::make(Str::random(40)),
            ],
        );

        $token = $user->createToken('qms-kiosk', [self::ABILITY]);

        $this->info("User servis: {$user->email} (id {$user->id}).");
        $this->info('Token Sanctum dijana - salin SEKARANG, tidak dipaparkan semula selepas ni:');
        $this->line($token->plainTextToken);

        return self::SUCCESS;
    }
}
