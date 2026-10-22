<?php

namespace App\Console\Commands;

use App\Support\DesignFamilyInheritor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('merchant:inherit-family {--dry-run : Papar rancangan sahaja, jangan ubah pangkalan data}')]
#[Description('Isi nickname & gambar kod design yang kosong drpd adik-beradik dalam keluarga reka bentuk yang sama, dan nickname sandaran drpd DiamondDesc')]
class InheritMerchantFamilyData extends Command
{
    public function handle(DesignFamilyInheritor $inheritor): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $inheritor->run($dryRun);

        $this->info(($dryRun ? '[DRY-RUN] ' : '').sprintf(
            '%d kod berubah: %d nickname diisi, %d gambar diisi, %d nickname sandaran DiamondDesc, %d kod dibersihkan.',
            count($result['updates']),
            $result['nickname_filled'],
            $result['image_filled'],
            $result['description_filled'],
            $result['cleared'],
        ));

        foreach (array_slice($result['updates'], 0, 10, true) as $code => $fields) {
            $this->line(sprintf('  %s => %s', $code, json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
        }

        return self::SUCCESS;
    }
}
