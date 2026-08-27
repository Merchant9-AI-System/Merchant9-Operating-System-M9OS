@props(['value'])

<div class="group relative overflow-x-auto rounded-lg bg-gray-950 p-3 pr-10">
    <pre class="text-xs text-gray-100"><code>{{ $value }}</code></pre>

    <button
        type="button"
        x-on:click="
            window.navigator.clipboard.writeText(@js($value))
            $tooltip('Disalin!', { theme: $store.theme, timeout: 1500 })
        "
        class="absolute right-2 top-2 rounded-md p-1 text-gray-400 transition-colors hover:bg-white/10 hover:text-gray-100"
    >
        <x-filament::icon icon="heroicon-o-clipboard" class="h-4 w-4" />
    </button>
</div>
