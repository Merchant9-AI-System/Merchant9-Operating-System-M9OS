<script setup lang="ts">
import { Search, X } from '@lucide/vue';
import {
    ComboboxAnchor,
    ComboboxContent,
    ComboboxInput,
    ComboboxItem,
    ComboboxPortal,
    ComboboxRoot,
    ComboboxViewport,
} from 'reka-ui';
import { computed, ref, watch } from 'vue';
import { InputGroup, InputGroupAddon, InputGroupButton } from '@/components/ui/input-group';
import { Spinner } from '@/components/ui/spinner';

/**
 * Carian autocomplete SEBARIS (bukan dialog) utk memilih supplier (kod / nama vendor). Padanan Vue bagi corak ReUI
 * Autocomplete (React/Base UI): penapis dimatikan kerana cadangan datang dari server (`ignore-filter`, setara filter={null}),
 * popup HANYA dipaparkan selepas ada teks, dan baris status di atas senarai (mencari / ralat / tiada hasil / bilangan hasil).
 * Dibina atas Combobox reka-ui (sama enjin dgn komponen shadcn-vue lain), bukan salinan fail React - Base UI tiada versi Vue.
 *
 * Pilih cadangan = emit `pick` (induk biasanya menetapkan teks medan kepada nilai terpilih; butang X = `clear`). Enter tanpa cadangan disorot memilih cadangan PERTAMA (jika ada).
 */
const props = withDefaults(
    defineProps<{
        placeholder?: string;
        suppliers: { code: string; name: string }[];
        loading?: boolean;
        // Mesej ralat (cth. rangkaian gagal) - dipaparkan pd baris status.
        error?: string | null;
    }>(),
    { placeholder: 'Cari...', loading: false, error: null },
);

const emit = defineEmits<{
    pick: [code: string, name: string];
    clear: [];
}>();

const text = defineModel<string>({ default: '' });

const open = ref(false);

const term = computed(() => text.value.trim());
const shouldRenderPopup = computed(() => term.value !== '');

// Baris status (corak AutocompleteStatus).
const status = computed(() => {
    if (props.loading) {
        return 'Mencari supplier...';
    }

    if (props.error) {
        return props.error;
    }

    if (props.suppliers.length === 0) {
        return `Tiada supplier untuk "${term.value}"`;
    }

    return `${props.suppliers.length} supplier ditemui`;
});

// Enter ditekan semasa cadangan masih dimuat: tunggu hasil, kemudian pilih yang pertama.
const enterPending = ref(false);

// Teks berubah = senarai cadangan baharu - Enter yg menunggu hasil lama dibatalkan.
watch(text, () => {
    enterPending.value = false;
});

function onPick(value: unknown) {
    const pick = value as { code: string; name: string } | null;

    if (!pick) {
        return;
    }

    open.value = false;
    emit('pick', pick.code, pick.name);
}

function onEnter() {
    if (props.loading) {
        enterPending.value = true;

        return;
    }

    // Item disorot: reka-ui yg memilihnya (onPick) - disemak terus pd DOM kerana acara highlight reka-ui kekal basi bila senarai
    // datang dari server. Tiada item disorot: pilih cadangan pertama.
    const first = props.suppliers[0];

    if (first && document.querySelector('[role="option"][data-highlighted]') === null) {
        onPick(first);
    }
}

watch(
    () => props.loading,
    (isLoading) => {
        if (isLoading || !enterPending.value) {
            return;
        }

        enterPending.value = false;

        const first = props.suppliers[0];

        if (first) {
            onPick(first);
        }
    },
);

// Medan boleh memaparkan pilihan sedia ada (nama supplier) - fokus memilih semua teks supaya menaip terus menggantikannya.
function selectAll(event: FocusEvent) {
    (event.target as HTMLInputElement | null)?.select();
}

function clear() {
    text.value = '';
    open.value = false;
    emit('clear');
}
</script>

<template>
    <ComboboxRoot v-model:open="open" :model-value="null" :ignore-filter="true" :reset-search-term-on-select="false"
        :reset-search-term-on-blur="false" @update:model-value="onPick">
        <ComboboxAnchor as-child>
            <InputGroup class="h-8 min-w-48 flex-1 bg-white">
                <!-- Teks carian = v-model pada ComboboxInput (BUKAN prop Root). Elemen <input> ASLI (bukan komponen Input /
                     InputGroupInput): komponen Input ada v-model sendiri yg bertembung dgn nilai yg ditetapkan ComboboxInput -
                     setiap render menimpa balik nilai, jadi aksara PERTAMA yg ditaip "hilang". Kelas = InputGroupInput. -->
                <ComboboxInput v-model="text" as-child>
                    <input data-slot="input-group-control" :placeholder="placeholder" autocomplete="off"
                        class="placeholder:text-muted-foreground selection:bg-primary selection:text-primary-foreground h-9 w-full min-w-0 flex-1 rounded-none border-0 bg-transparent px-3 py-1 text-base shadow-none outline-none focus-visible:ring-0 md:text-sm"
                        @focus="selectAll" @keydown.enter="onEnter">
                </ComboboxInput>
                <InputGroupAddon>
                    <Search />
                </InputGroupAddon>
                <InputGroupAddon v-if="loading || text" align="inline-end">
                    <Spinner v-if="loading" class="size-4" />
                    <InputGroupButton v-else type="button" size="icon-xs" aria-label="Kosongkan carian" @click="clear">
                        <X />
                    </InputGroupButton>
                </InputGroupAddon>
            </InputGroup>
        </ComboboxAnchor>

        <ComboboxPortal>
            <ComboboxContent v-if="shouldRenderPopup" position="popper" :side-offset="4" align="start"
                class="z-50 w-(--reka-combobox-trigger-width) overflow-hidden rounded-md border bg-popover text-popover-foreground shadow-md outline-none">
                <p class="flex items-center gap-2 border-b px-3 py-2 text-xs text-muted-foreground" role="status">
                    <Spinner v-if="loading" class="size-3.5" />
                    {{ status }}
                </p>

                <ComboboxViewport v-if="suppliers.length > 0" class="max-h-80 overflow-y-auto p-1">
                    <ComboboxItem v-for="supplier in suppliers" :key="supplier.code"
                        :value="{ code: supplier.code, name: supplier.name }"
                        class="flex cursor-default items-center justify-between gap-3 rounded-lg px-2 py-2 text-sm outline-none data-highlighted:bg-accent data-highlighted:text-accent-foreground">
                        <span class="truncate font-medium">{{ supplier.name }}</span>
                        <span class="font-mono text-xs text-muted-foreground">{{ supplier.code }}</span>
                    </ComboboxItem>
                </ComboboxViewport>
            </ComboboxContent>
        </ComboboxPortal>
    </ComboboxRoot>
</template>
