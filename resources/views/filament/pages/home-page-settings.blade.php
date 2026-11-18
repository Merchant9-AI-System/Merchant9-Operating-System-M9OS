<x-filament-panels::page>
    @php($canUpdate = $this->canUpdate())
    @php($meta = $this->moduleMeta())

    <x-filament::section
        heading="Halaman Home mengikut peranan"
        description="Halaman Home (ringkasan semalam) ialah halaman pertama selepas log masuk. Hidupkan untuk peranan yang patut melihatnya, kemudian pilih modul dan seret untuk menyusun urutannya bagi setiap peranan. Pengguna dengan peranan yang dimatikan terus ke dashboard admin. Pengguna dengan beberapa peranan mendapat gabungan modul semua peranannya yang dihidupkan."
    >
        @unless ($canUpdate)
            <x-filament::callout color="warning" icon="heroicon-m-lock-closed" heading="Baca sahaja">
                Anda hanya boleh melihat tetapan ini. Permission "Update:HomePageSettings" diperlukan untuk mengubahnya.
            </x-filament::callout>
        @endunless

        <form wire:submit="save" style="display: flex; flex-direction: column; gap: 1rem;">
            @foreach ($this->roleRows() as $row)
                @php($role = $row['role'])

                <x-filament::section
                    wire:key="role-{{ $role }}"
                    :heading="$row['label']"
                    :description="$row['users'].' pengguna · '.($row['has_branch'] ? 'Ada cawangan' : 'Tiada cawangan (modul cawangan dimalapkan)')"
                    collapsible
                    :collapsed="! ($enabled[$role] ?? false)"
                    compact
                    secondary
                >
                    <x-slot name="afterHeader">
                        <label style="display: flex; align-items: center; gap: 0.5rem;" x-on:click.stop>
                            <span class="fi-section-header-description">Halaman Home</span>
                            <x-filament::toggle
                                :state="'$wire.$entangle(\'enabled.'.$role.'\')'"
                                :disabled="! $canUpdate"
                                x-on:click.stop
                            />
                        </label>
                    </x-slot>

                    <ul
                        @if ($canUpdate) x-sortable data-sortable-animation-duration="200" x-on:end.stop="$wire.reorder('{{ $role }}', $event.target.sortable.toArray())" @endif
                        style="display: flex; flex-direction: column;"
                    >
                        @foreach ($layouts[$role] ?? [] as $index => $module)
                            @php($info = $meta[$module['key']] ?? null)
                            @continue($info === null)
                            @php($dimmed = $info['scope'] === 'branch' && ! $row['has_branch'])

                            <li
                                wire:key="mod-{{ $role }}-{{ $module['key'] }}-{{ $index }}"
                                x-sortable-item="{{ $module['key'] }}"
                                style="display: flex; align-items: center; gap: 0.75rem; padding: 0.5rem 0.25rem; border-top: 1px solid rgba(128, 128, 128, 0.2); {{ $dimmed ? 'opacity: 0.4;' : '' }}"
                            >
                                @if ($canUpdate)
                                    <span x-sortable-handle title="Seret untuk menyusun" style="cursor: grab; display: inline-flex; flex-shrink: 0;">
                                        <x-filament::icon icon="heroicon-m-bars-3" class="fi-icon" style="width: 1.25rem; height: 1.25rem; opacity: 0.6;" />
                                    </span>
                                @endif

                                <x-filament::toggle
                                    :state="'$wire.$entangle(\'layouts.'.$role.'.'.$index.'.enabled\')'"
                                    :disabled="! $canUpdate || $dimmed"
                                />

                                <span style="display: flex; flex-direction: column; min-width: 0; flex: 1 1 0%;">
                                    <span style="display: flex; align-items: center; flex-wrap: wrap; gap: 0.375rem; font-weight: 500;">
                                        {{ $info['title'] }}
                                        @if ($info['kind'] === 'pinned')
                                            <x-filament::badge size="xs" color="gray">di atas tab</x-filament::badge>
                                        @endif
                                        <x-filament::badge size="xs" :color="['branch' => 'info', 'company' => 'success', 'flex' => 'warning'][$info['scope']]">
                                            {{ ['branch' => 'Cawangan sendiri', 'company' => 'Syarikat', 'flex' => 'Universal'][$info['scope']] }}
                                        </x-filament::badge>
                                    </span>
                                    <span class="fi-section-header-description">
                                        {{ $info['description'] }}@if ($dimmed) · Perlu pengguna yang ada cawangan.@endif
                                    </span>

                                    @if ($info['scope'] === 'flex')
                                        {{-- Skop modul universal: cawangan sendiri / semua / cawangan dipilih. --}}
                                        <span style="display: flex; flex-wrap: wrap; align-items: center; gap: 0.75rem; margin-top: 0.5rem;">
                                            <x-filament::input.wrapper style="min-width: 12rem;" :disabled="! $canUpdate">
                                                <x-filament::input.select wire:model.live="layouts.{{ $role }}.{{ $index }}.scope_mode" :disabled="! $canUpdate">
                                                    <option value="own">Cawangan sendiri</option>
                                                    <option value="all">Semua cawangan</option>
                                                    <option value="selected">Cawangan dipilih</option>
                                                </x-filament::input.select>
                                            </x-filament::input.wrapper>

                                            @if (($module['scope_mode'] ?? 'own') === 'selected')
                                                @foreach ($this->storeOptions() as $code)
                                                    <label style="display: inline-flex; align-items: center; gap: 0.375rem;" wire:key="scope-{{ $role }}-{{ $module['key'] }}-{{ $code }}">
                                                        <x-filament::input.checkbox wire:model="layouts.{{ $role }}.{{ $index }}.scope_stores" value="{{ $code }}" :disabled="! $canUpdate" />
                                                        <span class="fi-section-header-description">{{ $code }}</span>
                                                    </label>
                                                @endforeach
                                            @elseif (($module['scope_mode'] ?? 'own') === 'own' && ! $row['has_branch'])
                                                <span class="fi-section-header-description">Peranan ini tiada cawangan - modul tidak akan dipaparkan kepada pengguna tanpa cawangan.</span>
                                            @endif
                                        </span>
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>

                    @if ($canUpdate)
                        <div style="padding-top: 0.75rem;">
                            <x-filament::button color="gray" size="xs" wire:click="resetRole('{{ $role }}')" wire:confirm="Kembalikan susun atur {{ $row['label'] }} kepada lalai?">
                                Kembalikan kepada lalai
                            </x-filament::button>
                        </div>
                    @endif
                </x-filament::section>
            @endforeach

            @if ($canUpdate)
                <div>
                    <x-filament::button type="submit">Simpan</x-filament::button>
                </div>
            @endif
        </form>
    </x-filament::section>
</x-filament-panels::page>
