<?php

namespace App\Console\Commands;

use App\Support\MerchantCatalogHarvester;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('merchant:harvest-catalog {--apply : Laksanakan perubahan (tanpa ini = dry-run)} {--from-cache : Guna tuaian tersimpan, jangan menuai semula} {--limit= : Hadkan bilangan awalan dicari (ujian)}')]
#[Description('Tuai pemetaan produk laman web merchant9.com -> kod design (senarai kod pada kad) dan isi nickname/gambar kod yang disenaraikan')]
class HarvestMerchantCatalog extends Command
{
    public function handle(MerchantCatalogHarvester $harvester): int
    {
        $apply = (bool) $this->option('apply');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $result = $harvester->run(
            dryRun: ! $apply,
            fromCache: (bool) $this->option('from-cache'),
            progress: function (int $done, int $total, int $cards): void {
                if ($done % 100 === 0 || $done === $total) {
                    $this->line(sprintf('  carian %d/%d, %d produk setakat ini', $done, $total, $cards));
                }
            },
            limit: $limit,
        );

        $stats = $result['plan']['stats'];

        $this->info(($apply ? '' : '[DRY-RUN] ').sprintf(
            '%d produk dituai%s. Kod disenaraikan: %d | ada dlm inventori: %d | bercanggah (dilangkau): %d | tak berubah: %d',
            $result['cards'],
            $result['harvested'] ? '' : ' (dari cache)',
            $stats['codes_listed'],
            $stats['in_db'],
            $stats['conflicts'],
            $stats['unchanged'],
        ));
        $this->info(sprintf(
            'Dikemas kini: %d baharu diisi, %d warisan/sandaran diganti, %d nilai asli diganti',
            $stats['filled'],
            $stats['replaced_inherited'],
            $stats['replaced_own'],
        ));

        foreach (array_slice($result['plan']['updates'], 0, 10, true) as $code => $fields) {
            $this->line(sprintf('  %s [%s] => %s', $code, $result['plan']['kinds'][$code], json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
        }

        return self::SUCCESS;
    }
}
