<x-filament-panels::page>
    <x-filament::section
        heading="Siapa dapat halaman Home"
        description="Halaman Home (ringkasan semalam) ialah halaman pertama selepas log masuk. Hidupkan untuk peranan yang patut melihatnya. Pengguna dengan peranan yang dimatikan terus ke dashboard admin. Pengguna dengan beberapa peranan dapat halaman ini jika mana-mana peranannya dihidupkan."
    >
        <form wire:submit="save" class="flex flex-col gap-6">
            <div class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($this->roleRows() as $row)
                    <label class="flex cursor-pointer items-center justify-between gap-4 py-3">
                        <span class="flex flex-col">
                            <span class="font-medium text-gray-950 dark:text-white">{{ $row['label'] }}</span>
                            <span class="text-sm text-gray-500 dark:text-gray-400">
                                {{ $row['users'] }} pengguna ·
                                @if ($row['has_summary'])
                                    Ada ringkasan
                                @else
                                    Pautan sahaja (belum ada ringkasan untuk peranan ini)
                                @endif
                            </span>
                        </span>
                        <x-filament::input.checkbox wire:model="enabled.{{ $row['role'] }}" />
                    </label>
                @endforeach
            </div>

            <div>
                <x-filament::button type="submit">Simpan</x-filament::button>
            </div>
        </form>
    </x-filament::section>
</x-filament-panels::page>
