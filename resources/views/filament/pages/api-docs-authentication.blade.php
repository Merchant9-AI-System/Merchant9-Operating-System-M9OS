<x-filament-panels::page>
    <div class="space-y-6">
        <div class="rounded-xl border border-gray-200 bg-white p-6 dark:border-white/10 dark:bg-white/5">
            <div class="mb-4 flex items-center gap-3">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary-50 text-sm font-semibold text-primary-600 dark:bg-primary-500/10 dark:text-primary-400">1</span>
                <div>
                    <h3 class="text-base font-semibold text-gray-950 dark:text-white">Token Sanctum</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400">skrip / curl / integrasi dalaman</p>
                </div>
                <x-filament::badge color="gray" class="ml-auto">{{ url('/mcp/restock') }}</x-filament::badge>
            </div>

            <p class="mb-3 text-sm text-gray-500 dark:text-gray-400">Utk client yg boleh simpan satu token tetap (bukan browser popup).</p>

            <ol class="list-decimal space-y-1.5 pl-5 text-sm text-gray-600 dark:text-gray-300">
                <li>Buka <b>Users</b> &rarr; pilih pengguna &rarr; tab <b>Token API</b>.</li>
                <li>Klik <b>Jana Token Baharu</b>, beri nama (cth. <code>claude-connector</code>).</li>
                <li>Salin token yg dipaparkan SEKARANG - tidak boleh dipapar semula selepas ni.</li>
                <li>
                    Hantar sbg header
                    <x-api-docs.copyable value="Authorization: Bearer <token>" />
                    ke
                    <x-api-docs.copyable :value="url('/mcp/restock')" />
                </li>
            </ol>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-6 dark:border-white/10 dark:bg-white/5">
            <div class="mb-4 flex items-center gap-3">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary-50 text-sm font-semibold text-primary-600 dark:bg-primary-500/10 dark:text-primary-400">2</span>
                <div>
                    <h3 class="text-base font-semibold text-gray-950 dark:text-white">OAuth (Claude.ai Custom Connector)</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400">aplikasi pihak ketiga</p>
                </div>
                <x-filament::badge color="gray" class="ml-auto">{{ url('/mcp/restock') }}</x-filament::badge>
            </div>

            <p class="mb-3 text-sm text-gray-500 dark:text-gray-400">URL SAMA dgn kaedah 1 - kaedah auth beza ikut client.</p>

            <ol class="list-decimal space-y-1.5 pl-5 text-sm text-gray-600 dark:text-gray-300">
                <li>Claude.ai &rarr; Settings &rarr; Connectors &rarr; <b>Add custom connector</b>.</li>
                <li>URL: <x-api-docs.copyable :value="url('/mcp/restock')" /></li>
                <li>Authentication: <b>Always required</b> (OAuth).</li>
                <li>OAuth client: <b>No client ID - register one automatically (DCR)</b> - <u>bukan</u> CIMD, tidak disokong.</li>
                <li>Log masuk & klik <b>Authorize</b> pd skrin kelulusan yg terbuka.</li>
            </ol>
        </div>

        <div @class([
            'rounded-xl border p-6',
            'border-gray-200 bg-white dark:border-white/10 dark:bg-white/5' => $this->adminMcpIsActive(),
            'border-gray-200 bg-gray-50 opacity-75 dark:border-white/10 dark:bg-white/[0.02]' => ! $this->adminMcpIsActive(),
        ])>
            <div class="mb-4 flex items-center gap-3">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-gray-100 text-sm font-semibold text-gray-500 dark:bg-white/10 dark:text-gray-400">3</span>
                <div>
                    <h3 class="text-base font-semibold text-gray-950 dark:text-white">Token guava (mcp:token)</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400">laluan Admin Panel Resources</p>
                </div>
                <div class="ml-auto flex items-center gap-2">
                    <x-filament::badge color="gray">{{ url('/mcp/admin') }}</x-filament::badge>
                    @if($this->adminMcpIsActive())
                        <x-filament::badge color="success">Aktif</x-filament::badge>
                    @else
                        <x-filament::badge color="gray">Tidak aktif</x-filament::badge>
                    @endif
                </div>
            </div>

            @if($this->adminMcpIsActive())
                <p class="mb-3 text-sm text-gray-500 dark:text-gray-400">Belum ada resource didedahkan di laluan ni lagi - rujuk menu "Overview".</p>
            @else
                <p class="mb-3 text-sm text-gray-500 dark:text-gray-400">Plugin belum diaktifkan di panel admin - langkah di bawah TAK akan berfungsi sehingga diaktifkan (rujuk menu "Overview").</p>
            @endif

            <x-api-docs.copyable-block value='php artisan mcp:token email@merchant9.com --name="Claude"' />

            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                Jana bearer token (format <code>gmcp_...</code>) khusus laluan <code>{{ url('/mcp/admin') }}</code> - berasingan drpd token Sanctum di atas.
            </p>
        </div>
    </div>
</x-filament-panels::page>
