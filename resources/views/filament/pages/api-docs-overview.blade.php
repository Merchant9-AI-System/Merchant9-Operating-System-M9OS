<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Apa itu MCP?</x-slot>

        <p class="text-sm text-gray-500 dark:text-gray-400">
            Model Context Protocol (MCP) ialah protokol standard yg membenarkan agen AI (Claude, dll.) memanggil
            "tool" - fungsi terhad & spesifik - terus atas sistem M9OS, dgn kebenaran ikut sama pengguna yg log
            masuk. M9OS ada <b>dua</b> server MCP berasingan, tujuan berbeza:
        </p>
    </x-filament::section>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <div class="flex flex-col gap-4 rounded-xl border border-gray-200 bg-white p-6 dark:border-white/10 dark:bg-white/5">
            <div class="flex items-start justify-between gap-3">
                <div class="flex min-w-0 items-center gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-primary-600 dark:bg-primary-500/10 dark:text-primary-400">
                        <x-filament::icon icon="heroicon-o-cube" class="h-5 w-5" />
                    </span>
                    <div class="min-w-0">
                        <h3 class="text-base font-semibold text-gray-950 dark:text-white">Restock Intelligence</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Server tersuai</p>
                    </div>
                </div>
                <x-filament::badge color="success" icon="heroicon-o-check-circle" class="shrink-0">Aktif</x-filament::badge>
            </div>

            <x-api-docs.copyable :value="url('/mcp/restock')" />

            <div class="flex flex-wrap gap-1.5">
                <x-filament::badge color="gray">token Sanctum</x-filament::badge>
                <x-filament::badge color="gray">OAuth</x-filament::badge>
            </div>

            <ul class="space-y-1.5 text-sm text-gray-600 dark:text-gray-300">
                <li class="flex items-start gap-2">
                    <x-filament::icon icon="heroicon-o-check" class="mt-0.5 h-4 w-4 shrink-0 text-success-500" />
                    <span>{{ count($this->getRestockTools()) }} tool tersedia sekarang - rujuk menu "Available Tools"</span>
                </li>
                <li class="flex items-start gap-2">
                    <x-filament::icon icon="heroicon-o-check" class="mt-0.5 h-4 w-4 shrink-0 text-success-500" />
                    <span>Claude.ai (via OAuth) & Claude Code/Desktop/Cursor (via token) boleh sambung</span>
                </li>
            </ul>
        </div>

        <div class="flex flex-col gap-4 rounded-xl border border-gray-200 bg-white p-6 dark:border-white/10 dark:bg-white/5">
            <div class="flex items-start justify-between gap-3">
                <div class="flex min-w-0 items-center gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-500 dark:bg-white/10 dark:text-gray-400">
                        <x-filament::icon icon="heroicon-o-squares-2x2" class="h-5 w-5" />
                    </span>
                    <div class="min-w-0">
                        <h3 class="text-base font-semibold text-gray-950 dark:text-white">Admin Panel Resources</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400">guava/filament-mcp</p>
                    </div>
                </div>

                @if($this->adminMcpIsActive())
                    <x-filament::badge color="success" icon="heroicon-o-check-circle" class="shrink-0">Aktif</x-filament::badge>
                @else
                    <x-filament::badge color="gray" icon="heroicon-o-pause-circle" class="shrink-0">Tidak aktif</x-filament::badge>
                @endif
            </div>

            @if($this->adminMcpIsActive())
                <x-api-docs.copyable :value="url('/mcp/admin')" />

                <div class="flex flex-wrap gap-1.5">
                    <x-filament::badge color="gray">token gmcp_</x-filament::badge>
                </div>

                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Auto-jana tool CRUD drpd resource Filament yg didaftar - <b>belum ada resource didedahkan lagi</b>
                    (sengaja, tunggu keputusan resource mana yg selamat utk agen AI capai).
                </p>
            @else
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Plugin dah dipasang tapi <b>belum diaktifkan</b> di panel admin - <code>McpPlugin::make()</code>
                    dikomen di <code>AdminPanelProvider::panel()</code>. Tiada endpoint <code>/mcp/admin</code>
                    berjalan buat masa ni.
                </p>
            @endif
        </div>
    </div>
</x-filament-panels::page>
