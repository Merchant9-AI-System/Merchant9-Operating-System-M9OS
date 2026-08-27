@props(['value'])

<button
    type="button"
    x-on:click="
        window.navigator.clipboard.writeText(@js($value))
        $tooltip('Disalin!', { theme: $store.theme, timeout: 1500 })
    "
    {{ $attributes->class(['inline-flex items-center gap-1.5 rounded-md bg-gray-950 px-2 py-1 font-mono text-xs text-gray-100 transition-colors hover:bg-gray-800']) }}
>
    <span>{{ $value }}</span>
    <x-filament::icon icon="heroicon-o-clipboard" class="h-3.5 w-3.5 shrink-0 text-gray-400" />
</button>
