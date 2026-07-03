<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Muat Naik Data JEMiSys</x-slot>

        <p class="text-sm text-gray-500 dark:text-gray-400">
            Muat naik fail <code>.sql</code> (dump dijana drpd export JEMiSys .xlsx) utk ganti data dlm
            <code>jemisys.db</code>. Backup automatik akan dibuat sebelum proses (<code>jemisys.db.pre_load_&lt;tarikh&gt;.bak</code>);
            kalau import gagal separuh jalan, jemisys.db dikembalikan ke keadaan asal secara automatik.
        </p>
    </x-filament::section>
</x-filament-panels::page>
