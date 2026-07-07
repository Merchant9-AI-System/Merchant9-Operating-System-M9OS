<x-filament-panels::page>
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
