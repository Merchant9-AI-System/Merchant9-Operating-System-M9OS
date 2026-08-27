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
