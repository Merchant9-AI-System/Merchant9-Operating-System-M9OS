<x-filament-panels::page>
    <x-filament::section icon="heroicon-o-arrow-path" icon-size="sm" icon-color="primary" wire:poll.3s.visible="$refresh">
        <x-slot name="heading">
            Sync Dari JEMiSys
        </x-slot>

        <x-slot name="afterHeader">
            @if ($this->mirrorStatus['syncing'])
                {{-- <x-filament::loading-indicator class="h-3 w-3" /> --}}
                <x-filament::badge color="warning" size="sm" icon="heroicon-m-arrow-path">
                    Syncing...
                </x-filament::badge>
            @elseif ($this->mirrorStatus['lastSyncedAt'])
                <x-filament::badge color="success" size="sm" icon="heroicon-m-clock">
                    Last Synced
                    {{ \Illuminate\Support\Carbon::parse($this->mirrorStatus['lastSyncedAt'])->diffForHumans() }}
                </x-filament::badge>
            @else
                <x-filament::badge color="gray" size="sm">
                    Never Sync
                </x-filament::badge>
            @endif
        </x-slot>

        <div class="flex items-center justify-between gap-1.5">
            @foreach ($this->mirrorStatus['mirrors'] as $label => $count)
                <x-filament::badge color="gray" size="sm">
                    {{ $label }}: {{ number_format($count) }}
                </x-filament::badge>
            @endforeach
        </div>
    </x-filament::section>

    {{-- <x-filament::section icon="heroicon-o-photo" icon-size="sm" icon-color="primary" wire:poll.3s.visible="$refresh">
        <x-slot name="heading">
            Sync Nickname &amp; Imej Merchant9
        </x-slot>

        <x-slot name="afterHeader">
            @if ($this->merchantNicknameStatus['syncing'])
                <x-filament::badge color="warning" size="sm" icon="heroicon-m-arrow-path">
                    Syncing...
                </x-filament::badge>
            @elseif ($this->merchantNicknameStatus['lastCompletedAt'])
                <x-filament::badge color="success" size="sm" icon="heroicon-m-clock">
                    Last Completed
                    {{ \Illuminate\Support\Carbon::parse($this->merchantNicknameStatus['lastCompletedAt'])->diffForHumans() }}
                </x-filament::badge>
            @else
                <x-filament::badge color="gray" size="sm">
                    Never Sync
                </x-filament::badge>
            @endif
        </x-slot>

        <div class="flex items-center justify-between gap-1.5">
            <x-filament::badge :color="$this->merchantNicknameStatus['missingCount'] > 0 ? 'warning' : 'success'" size="sm">
                Belum Diisi: {{ number_format($this->merchantNicknameStatus['missingCount']) }} / {{ number_format($this->merchantNicknameStatus['totalDistinctCount']) }} kod unik
            </x-filament::badge>
        </div>
    </x-filament::section> --}}

    @foreach ($checks as $check)
        <x-filament::callout :icon="match ($check['status']) {
            'ok' => 'heroicon-o-check-circle',
            'fail' => 'heroicon-o-x-circle',
            default => 'heroicon-o-minus-circle',
        }" :color="match ($check['status']) {
            'ok' => 'success',
            'fail' => 'danger',
            default => 'gray',
        }" icon-size="sm">
            <x-slot name="heading">
                {{ $check['label'] }}
            </x-slot>

            <x-slot name="description">
                {{ $check['detail'] }}
            </x-slot>

            @if ($check['ms'] !== null)
                <x-slot name="footer">
                    <x-filament::badge color="gray" size="sm">
                        {{ $check['ms'] }}ms
                    </x-filament::badge>
                </x-slot>
            @endif
        </x-filament::callout>
    @endforeach
</x-filament-panels::page>
