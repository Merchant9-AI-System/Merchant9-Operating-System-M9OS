<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">
            <div class="flex items-center gap-2">
                <x-filament::icon icon="heroicon-o-cube" class="h-5 w-5 text-primary-500" />
                Inventory Intelligence
            </div>
        </x-slot>
        <x-slot name="description">
            <span class="font-mono">{{ url('/mcp/inventory') }}</span> - senarai di bawah auto-baca drpd
            <code>InventoryServer.php</code>, sentiasa padan dgn apa yg sebenarnya didaftar. Klik "Try it out"
            utk hantar panggilan <code>tools/call</code> SEBENAR guna token anda sendiri.
        </x-slot>

        <div class="mb-4">
            <label class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">Bearer token (utk "Try it out")</label>
            <x-filament::input.wrapper>
                <x-filament::input type="password" wire:model="bearerToken" placeholder="Tampal token Sanctum/OAuth di sini..." />
            </x-filament::input.wrapper>
            <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">Tidak disimpan - hanya dipakai utk permintaan try-it-out semasa sesi ni. Rujuk menu "Authentication & Tokens" utk cara jana token.</p>
        </div>

        <div class="space-y-3">
            @foreach($this->getInventoryTools() as $i => $tool)
                @php $isExpanded = $expandedTool === $tool['name']; @endphp

                <div class="rounded-lg border border-gray-200 dark:border-white/10">
                    <div class="flex items-center gap-3 p-4">
                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary-50 text-xs font-semibold text-primary-600 dark:bg-primary-500/10 dark:text-primary-400">
                            {{ $i + 1 }}
                        </span>
                        <div class="min-w-0 flex-1 space-y-1">
                            <code class="text-sm font-semibold text-primary-600 dark:text-primary-400">{{ $tool['name'] }}</code>
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $tool['description'] }}</p>
                        </div>
                        <button
                            type="button"
                            wire:click="toggleTool('{{ $tool['name'] }}')"
                            class="inline-flex shrink-0 items-center gap-1.5 rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 dark:border-white/10 dark:text-gray-200 dark:hover:bg-white/5"
                        >
                            {{ $isExpanded ? 'Tutup' : 'Try it out' }}
                        </button>
                    </div>

                    @if($isExpanded)
                        <div class="space-y-4 border-t border-gray-200 p-4 dark:border-white/10">
                            @if(count((array) ($tool['inputSchema']['properties'] ?? [])) > 0)
                                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    @foreach((array) $tool['inputSchema']['properties'] as $field => $property)
                                        @php $isRequired = in_array($field, (array) ($tool['inputSchema']['required'] ?? []), true); @endphp

                                        <div class="{{ in_array($property['type'] ?? 'string', ['boolean', 'array']) ? 'sm:col-span-2' : '' }}">
                                            <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">
                                                <code>{{ $field }}</code>
                                                @if($isRequired)
                                                    <span class="text-danger-500">*</span>
                                                @endif
                                            </label>

                                            @if(($property['type'] ?? 'string') === 'boolean')
                                                <label class="flex items-center gap-2">
                                                    <x-filament::input.checkbox wire:model="toolInputs.{{ $tool['name'] }}.{{ $field }}" />
                                                    <span class="text-sm text-gray-500 dark:text-gray-400">{{ $property['description'] ?? '' }}</span>
                                                </label>
                                            @else
                                                <x-filament::input.wrapper>
                                                    <x-filament::input
                                                        type="{{ ($property['type'] ?? 'string') === 'integer' ? 'number' : 'text' }}"
                                                        wire:model="toolInputs.{{ $tool['name'] }}.{{ $field }}"
                                                        placeholder="{{ ($property['type'] ?? 'string') === 'array' ? 'Pisahkan dgn koma - cth. HQ, SECURITY' : ($property['description'] ?? '') }}"
                                                    />
                                                </x-filament::input.wrapper>
                                                @if(($property['type'] ?? 'string') === 'array' && filled($property['description'] ?? null))
                                                    <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">{{ $property['description'] }}</p>
                                                @endif
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <p class="text-sm text-gray-400">Tool ni tiada input.</p>
                            @endif

                            <button
                                type="button"
                                wire:click="sendToolRequest('{{ $tool['name'] }}')"
                                wire:loading.attr="disabled"
                                wire:target="sendToolRequest('{{ $tool['name'] }}')"
                                class="inline-flex items-center gap-1.5 rounded-md bg-primary-600 px-3 py-1.5 text-sm font-medium text-white transition-colors hover:bg-primary-500 disabled:opacity-50"
                            >
                                <x-filament::icon icon="heroicon-o-paper-airplane" class="h-4 w-4" />
                                <span wire:loading.remove wire:target="sendToolRequest('{{ $tool['name'] }}')">Send request</span>
                                <span wire:loading wire:target="sendToolRequest('{{ $tool['name'] }}')">Menghantar...</span>
                            </button>

                            @if(isset($toolResponses[$tool['name']]))
                                @php $response = $toolResponses[$tool['name']]; @endphp
                                <div class="space-y-2">
                                    <x-filament::badge :color="$response['isError'] ? 'danger' : 'success'">
                                        {{ $response['isError'] ? 'Ralat' : 'Berjaya' }}
                                    </x-filament::badge>

                                    <x-api-docs.copyable-block :value="$response['structuredContent'] !== null ? json_encode($response['structuredContent'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $response['text']" />
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">
            <div class="flex items-center gap-2">
                <x-filament::icon icon="heroicon-o-chart-bar" class="h-5 w-5 text-primary-500" />
                Business Insights
                <x-filament::badge color="warning" size="sm">Local sahaja</x-filament::badge>
            </div>
        </x-slot>
        <x-slot name="description">
            Jualan &amp; margin (JEMiSys), perbelanjaan pembekal (FINM9), ahli, harga emas, dan simpanan insight agen.
            Senarai di bawah auto-baca drpd <code>InsightsServer.php</code>. Server ni <b>local (stdio) sahaja</b> - tiada URL web
            atau bearer token, jadi tiada "Try it out"; guna Claude Code / Desktop (daftar dlm <code>.mcp.json</code>) atau
            <code>php artisan mcp:inspector insights</code>.
        </x-slot>

        <div class="mb-4 flex flex-wrap gap-2">
            <x-api-docs.copyable value="php artisan mcp:start insights" />
            <x-api-docs.copyable value="php artisan mcp:inspector insights" />
        </div>

        <div class="space-y-3">
            @foreach($this->getInsightsTools() as $i => $tool)
                @php
                    $isOpen = $expandedInsightsTool === $tool['name'];
                    $properties = (array) ($tool['inputSchema']['properties'] ?? []);
                @endphp

                <div class="rounded-lg border border-gray-200 dark:border-white/10">
                    <div class="flex items-center gap-3 p-4">
                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary-50 text-xs font-semibold text-primary-600 dark:bg-primary-500/10 dark:text-primary-400">
                            {{ $i + 1 }}
                        </span>
                        <div class="min-w-0 flex-1 space-y-1">
                            <code class="text-sm font-semibold text-primary-600 dark:text-primary-400">{{ $tool['name'] }}</code>
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $tool['description'] }}</p>
                        </div>
                        <button
                            type="button"
                            wire:click="toggleInsightsTool('{{ $tool['name'] }}')"
                            class="inline-flex shrink-0 items-center gap-1.5 rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 dark:border-white/10 dark:text-gray-200 dark:hover:bg-white/5"
                        >
                            {{ $isOpen ? 'Tutup' : 'Parameter' }}
                        </button>
                    </div>

                    @if($isOpen)
                        <div class="space-y-3 border-t border-gray-200 p-4 dark:border-white/10">
                            @if(count($properties) > 0)
                                <div class="overflow-x-auto">
                                    <table class="w-full text-left text-sm">
                                        <thead>
                                            <tr class="text-xs uppercase tracking-wide text-gray-400">
                                                <th class="py-1 pr-4 font-medium">Parameter</th>
                                                <th class="py-1 pr-4 font-medium">Jenis</th>
                                                <th class="py-1 font-medium">Keterangan</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                                            @foreach($properties as $field => $property)
                                                <tr class="align-top">
                                                    <td class="py-1.5 pr-4 whitespace-nowrap">
                                                        <code>{{ $field }}</code>
                                                        @if(in_array($field, (array) ($tool['inputSchema']['required'] ?? []), true))
                                                            <span class="text-danger-500">*</span>
                                                        @endif
                                                    </td>
                                                    <td class="py-1.5 pr-4 whitespace-nowrap text-gray-500 dark:text-gray-400">{{ $property['type'] ?? 'string' }}</td>
                                                    <td class="py-1.5 text-gray-600 dark:text-gray-300">{{ $property['description'] ?? '' }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @else
                                <p class="text-sm text-gray-400">Tool ni tiada input.</p>
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="mt-6 space-y-2">
            <h4 class="text-sm font-semibold text-gray-950 dark:text-white">Server ikut peranan agen</h4>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Setiap peranan hanya nampak tool yg berkaitan (<code>config/insights_roles.php</code>), dan <code>write-insight</code> /
                <code>get-previous-insights</code> dikunci kpd peranan itu (CEO sahaja boleh baca insight semua peranan).
                Server penuh <code>insights</code> di atas tak dikunci - utk admin/pembangun.
            </p>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="text-xs uppercase tracking-wide text-gray-400">
                            <th class="py-1 pr-4 font-medium">Peranan</th>
                            <th class="py-1 pr-4 font-medium">Perintah</th>
                            <th class="py-1 font-medium">Tool</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach($this->getInsightsRoleServers() as $server)
                            <tr class="align-top">
                                <td class="py-2 pr-4 font-medium whitespace-nowrap text-gray-950 dark:text-white">{{ $server['label'] }}</td>
                                <td class="py-2 pr-4"><x-api-docs.copyable :value="'php artisan mcp:start '.$server['handle']" /></td>
                                <td class="py-2">
                                    <div class="flex flex-wrap gap-1">
                                        @foreach($server['tools'] as $toolName)
                                            <x-filament::badge color="gray" size="sm">{{ $toolName }}</x-filament::badge>
                                        @endforeach
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">
            <div class="flex items-center gap-2">
                <x-filament::icon icon="heroicon-o-squares-2x2" class="h-5 w-5 text-gray-400" />
                Admin Panel Resources
                @if($this->adminMcpIsActive())
                    <x-filament::badge color="success" size="sm">Aktif</x-filament::badge>
                @else
                    <x-filament::badge color="gray" size="sm">Tidak aktif</x-filament::badge>
                @endif
            </div>
        </x-slot>
        <x-slot name="description">Kuasa kuasa oleh guava/filament-mcp - membenarkan agen AI CRUD terus atas resource Filament.</x-slot>

        @if($this->adminMcpIsActive())
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Belum ada resource didedahkan lagi di laluan <span class="font-mono">{{ url('/mcp/admin') }}</span> -
                keputusan sengaja, sbb setiap resource yg didedahkan bermakna agen AI (Claude dll.) boleh baca/tulis
                data tsb terus (ikut CRUD/read-only yg ditetapkan & polisi Filament sedia ada). Bila perlu, tambah via
                <code>McpResource::make(...)</code> di <code>AdminPanelProvider::panel()</code> - rujuk komen di situ.
            </p>
        @else
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Plugin dah dipasang tapi belum diaktifkan di panel admin - tiada tool utk disenaraikan buat masa ni.
                Rujuk menu "Overview" utk konteks penuh.
            </p>
        @endif
    </x-filament::section>
</x-filament-panels::page>
