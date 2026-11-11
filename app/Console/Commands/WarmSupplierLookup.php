<?php

namespace App\Console\Commands;

use App\Support\SupplierLookup;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('supplier-lookup:warm')]
#[Description('Panaskan cache agregat halaman Supplier Lookup untuk setiap tempoh (pantas jika cache sudah ada)')]
class WarmSupplierLookup extends Command
{
    public function handle(): int
    {
        foreach (SupplierLookup::PERIOD_DAYS as $days) {
            $start = microtime(true);
            $groups = SupplierLookup::forDays($days)->warm();

            $this->line(sprintf('%3d hari: %s baris design x cawangan (%d ms)', $days, number_format($groups), round((microtime(true) - $start) * 1000)));
        }

        return self::SUCCESS;
    }
}
