<?php

namespace App\Console\Commands;

use App\Support\RestockSnapshotMaterializer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Bina semula ringkasan restock (restock_snapshots, restock_bucket_snapshots, restock_verdict_changes) drpd cermin inventori.
 * Dijalankan automatik oleh SyncJemisysMirrors selepas segerak harian; arahan ini utk larian manual (cth. selepas deploy pertama).
 */
#[Signature('app:build-restock-snapshot')]
#[Description('Bina semula ringkasan restock (design x cawangan & bucket saiz/berat) drpd cermin inventori.')]
class BuildRestockSnapshot extends Command
{
    public function handle(): int
    {
        $start = microtime(true);
        $result = RestockSnapshotMaterializer::materialize();
        $seconds = round(microtime(true) - $start, 1);

        $this->info("Selesai ({$seconds}s): {$result['designs']} design x cawangan, {$result['buckets']} bucket, {$result['changes']} perubahan Cadangan.");

        return self::SUCCESS;
    }
}
