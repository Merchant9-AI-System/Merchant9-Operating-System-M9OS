<x-filament-panels::page>
    <x-filament::section wire:poll.3s.visible="$refresh">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                @if ($this->mirrorStatus['syncing'])
                    <x-filament::icon icon="heroicon-o-arrow-path" class="h-5 w-5 shrink-0 animate-spin"
                        style="color: var(--warning-500)" />
                @endif

                <div>
                    <p class="font-medium text-sm">Cermin Tempatan JEMiSys</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        @foreach ($this->mirrorStatus['mirrors'] as $label => $count)
                            {{ $label }}: {{ number_format($count) }}@if (!$loop->last), @endif
                        @endforeach
                        -
                        @if ($this->mirrorStatus['syncing'])
                            <span class="animate-pulse">sedang segerak...</span>
                        @elseif ($this->mirrorStatus['lastSyncedAt'])
                            segerak terakhir {{ \Illuminate\Support\Carbon::parse($this->mirrorStatus['lastSyncedAt'])->diffForHumans() }}
                        @else
                            belum pernah disegerak
                        @endif
                    </p>
                </div>
            </div>

            @if ($this->mirrorStatus['syncing'] && $this->mirrorStatus['syncStartedAt'])
                <span
                    x-data="{
                        startedAt: new Date('{{ $this->mirrorStatus['syncStartedAt'] }}').getTime(),
                        elapsed: '00:00',
                        tick() {
                            const secs = Math.max(0, Math.floor((Date.now() - this.startedAt) / 1000));
                            const m = String(Math.floor(secs / 60)).padStart(2, '0');
                            const s = String(secs % 60).padStart(2, '0');
                            this.elapsed = `${m}:${s}`;
                        },
                    }"
                    x-init="tick(); setInterval(() => tick(), 1000)"
                    x-text="elapsed"
                    class="text-xs font-mono text-gray-400 shrink-0"
                ></span>
            @endif
        </div>
    </x-filament::section>

    <div class="grid grid-cols-2 gap-2 space-y-1">
        @foreach ($checks as $check)
            <x-filament::section>
                <div class="flex items-start justify-between">
                    <div class="flex flex-1">
                        @if ($check['status'] === 'ok')
                            <x-filament::icon icon="heroicon-o-check-circle" class="h-6 w-6 shrink-0"
                                style="color: var(--success-500)" />
                        @elseif ($check['status'] === 'fail')
                            <x-filament::icon icon="heroicon-o-x-circle" class="h-6 w-6 shrink-0"
                                style="color: var(--danger-500)" />
                        @else
                            <x-filament::icon icon="heroicon-o-minus-circle" class="h-6 w-6 shrink-0"
                                style="color: var(--gray-400)" />
                        @endif
                        <p class="font-medium text-sm">{{ $check['label'] }}</p>

                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $check['detail'] }}</p>
                        </div>
                    </div>

                    @if ($check['ms'] !== null)
                        <span class="text-xs text-gray-400 shrink-0">{{ $check['ms'] }}ms</span>
                    @endif
                </div>
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
