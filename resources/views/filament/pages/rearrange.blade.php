<x-filament-panels::page>
    <p class="fi-section-header-description text-sm text-gray-500 dark:text-gray-400">
        Kalau satu cawangan ada lebih daripada 1 unit sesuatu design, dan cawangan lain sudah
        <b>sold out</b> (stok = 0) tetapi <b>pernah menjual</b> design itu, sistem cadangkan pindah
        stok supaya barang laku ada di tempat yang ada permintaan. (Kedai online dikecualikan.)
    </p>

    {{ $this->table }}
</x-filament-panels::page>
