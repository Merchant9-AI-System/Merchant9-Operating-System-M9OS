<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Backfill username utk pengguna sedia ada yg belum ada (rujuk migration
 * add_column_username_at_table_users & skrin login tersuai App\Filament\Pages\Auth\Login yg
 * benarkan log masuk guna username ATAU email). Username = bahagian sebelum "@" emel,
 * huruf kecil (cth. "leaderwm@m9.com" -> "leaderwm") - x kira domain (m9.com/luar), sentiasa
 * buang bahagian domain penuh. Pengguna yg SUDAH ada username (cth. superadmin, ditetapkan
 * manual) dilangkau - command ni selamat dijalankan berulang kali.
 */
#[Signature('app:generate-usernames')]
#[Description('Jana username drpd emel (bahagian sebelum @, huruf kecil) utk pengguna yg belum ada username.')]
class GenerateUsernamesFromEmail extends Command
{
    public function handle(): int
    {
        $users = User::whereNull('username')->get();

        if ($users->isEmpty()) {
            $this->info('Tiada pengguna perlu username - semua dah ada.');

            return self::SUCCESS;
        }

        $generated = 0;
        $skipped = 0;

        foreach ($users as $user) {
            $username = Str::lower(Str::before($user->email, '@'));

            if (User::where('username', $username)->where('id', '!=', $user->id)->exists()) {
                $this->warn("Langkau {$user->email} - username \"{$username}\" dah dipakai pengguna lain.");
                $skipped++;

                continue;
            }

            $user->update(['username' => $username]);
            $this->line("{$user->email} -> {$username}");
            $generated++;
        }

        $this->info("Selesai - {$generated} username dijana, {$skipped} dilangkau (pertembungan).");

        return self::SUCCESS;
    }
}
