@php
    use App\Support\DomainNotice;

    $newHost = DomainNotice::newHost();
    $targetUrl = DomainNotice::targetUrl(request());
@endphp

{{-- Modal "domain telah bertukar" - dipaparkan automatik sekali; "Faham" mengingati pilihan dlm pelayar ini
     (localStorage, per domain baharu). Tutup dgn X / Esc tak mengingati, jadi ia muncul semula pada muatan seterusnya. --}}
<div
    x-data="{
        storageKey: @js('m9os-domain-notice:' . $newHost),
        acknowledged() {
            try { return localStorage.getItem(this.storageKey) === '1' } catch (e) { return false }
        },
        acknowledge() {
            try { localStorage.setItem(this.storageKey, '1') } catch (e) {}
            $dispatch('close-modal', { id: 'domain-notice' })
        },
    }"
    x-init="if (! acknowledged()) { $nextTick(() => $dispatch('open-modal', { id: 'domain-notice' })) }"
>
    <x-filament::modal
        id="domain-notice"
        width="lg"
        icon="heroicon-o-globe-alt"
        icon-color="warning"
        :close-by-clicking-away="false"
    >
        <x-slot name="heading">Domain Merchant9 OS telah bertukar</x-slot>

        <x-slot name="description">
            Sistem ini kini boleh dicapai melalui alamat baharu. Sila guna alamat baharu dan kemas kini bookmark anda.
        </x-slot>

        <div class="flex flex-col gap-3 text-sm">
            <div>
                <p class="text-gray-500 dark:text-gray-400">Alamat baharu</p>
                <p class="font-mono font-semibold break-all text-gray-950 dark:text-white">{{ config('domain_notice.new_url') }}</p>
            </div>
            <div>
                <p class="text-gray-500 dark:text-gray-400">Alamat yang anda guna sekarang</p>
                <p class="font-mono break-all text-gray-950 dark:text-white">{{ request()->getHost() }}</p>
            </div>
            <p class="text-gray-500 dark:text-gray-400">
                Log masuk di alamat baharu menggunakan emel dan kata laluan yang sama.
            </p>
        </div>

        <x-slot name="footerActions">
            <x-filament::button tag="a" :href="$targetUrl" icon="heroicon-m-arrow-top-right-on-square">
                Pergi ke alamat baharu
            </x-filament::button>
            <x-filament::button color="gray" x-on:click="acknowledge()">
                Faham
            </x-filament::button>
        </x-slot>
    </x-filament::modal>
</div>
