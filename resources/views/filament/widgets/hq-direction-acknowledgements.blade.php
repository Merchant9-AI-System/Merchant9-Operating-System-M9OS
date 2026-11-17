{{-- Status akuan "Faham" setiap cawangan sasaran bagi satu arahan HQ (rujuk HqDirectionsWidget). --}}
@php
    /** @var \App\Models\HqDirection $direction */
    $acks = $direction->acknowledgements->keyBy(fn ($a) => \App\Models\HqDirection::normalizeStore($a->store_code));
    $stores = $direction->targetStores();
@endphp

<div class="flex flex-col gap-2 text-sm">
    @forelse ($stores as $store)
        @php $ack = $acks->get($store); @endphp
        <div class="flex items-center justify-between gap-4 rounded-lg border border-gray-200 px-3 py-2 dark:border-white/10">
            <span class="font-medium">{{ $store }}</span>

            @if ($ack)
                <span class="text-success-600 dark:text-success-400">
                    Faham · {{ $ack->acknowledged_by_name }} · {{ $ack->acknowledged_at->copy()->setTimezone(\App\Models\HqDirection::TIMEZONE)->format('d/m/Y H:i') }}
                </span>
            @else
                <span class="text-warning-600 dark:text-warning-400">Belum faham</span>
            @endif
        </div>
    @empty
        <p class="text-gray-500 dark:text-gray-400">Tiada cawangan sasaran.</p>
    @endforelse
</div>
