<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';

// Butang Kembali ke dashboard panel admin (Filament) di sebelah tajuk halaman. `dashboardUrl` dikongsi semua halaman Inertia oleh
// App\Http\Middleware\HandleInertiaRequests. Dashboard ialah halaman Filament (bukan Inertia), jadi ia pautan biasa (muat penuh).
const page = usePage();
const href = computed(() => (page.props.dashboardUrl as string | undefined) ?? '/admin');
</script>

<template>
    <Button as="a" :href="href" variant="outline" size="icon-sm" class="mt-0.5 shrink-0 bg-card" aria-label="Kembali ke dashboard" title="Kembali ke dashboard">
        <ArrowLeft class="size-4" />
    </Button>
</template>
