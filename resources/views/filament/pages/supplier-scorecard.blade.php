<x-filament-panels::page>
    <p class="fi-section-header-description text-sm text-gray-500 dark:text-gray-400">
        Prestasi supplier dikira daripada Purchase Order &amp; Goods Receipt sebenar (bukan data
        JEMiSys). Fill Rate = kuantiti diterima / kuantiti diorder. Lead Time = purata hari dari
        PO diluluskan sehingga penerimaan pertama. Jadual kosong sehingga ada PO sebenar dicipta.
    </p>

    {{ $this->table }}
</x-filament-panels::page>
