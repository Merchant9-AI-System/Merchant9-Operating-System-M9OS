<script setup lang="ts">
import { Check, CirclePlus } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Separator } from '@/components/ui/separator';
import { cn } from '@/lib/utils';

// Penapis berbilang pilihan dgn kiraan (corak DataTableFacetedFilter contoh "tasks" shadcn-vue).
const props = defineProps<{
    title: string;
    options: { value: string; label: string; count?: number }[];
}>();

// Popover.vue mengisytiharkan `open` sbg prop boolean - tanpa v-model:open, Vue menetapkannya false
// dan popover terkunci tertutup, jadi keadaan buka/tutup dikawal di sini.
const open = ref(false);

const selected = defineModel<string[]>({ default: () => [] });

const selectedSet = computed(() => new Set(selected.value));

function toggle(value: string) {
    selected.value = selectedSet.value.has(value)
        ? selected.value.filter((v) => v !== value)
        : [...selected.value, value];
}

const selectedLabels = computed(() => props.options.filter((o) => selectedSet.value.has(o.value)).map((o) => o.label));
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <Button type="button" variant="outline" size="sm" class="h-8 border-dashed">
                <CirclePlus class="size-4 mr-1" />
                {{ title }}
                <template v-if="selected.length > 0">
                    <Separator orientation="vertical" class="mx-2 h-4" />
                    <Badge variant="secondary" class="rounded-sm px-1 font-normal lg:hidden">{{ selected.length }}</Badge>
                    <div class="hidden gap-1 lg:flex">
                        <Badge v-if="selected.length > 2" variant="secondary" class="rounded-sm px-1 font-normal">
                            {{ selected.length }} dipilih
                        </Badge>
                        <template v-else>
                            <Badge v-for="label in selectedLabels" :key="label" variant="secondary" class="rounded-sm px-1 font-normal">
                                {{ label }}
                            </Badge>
                        </template>
                    </div>
                </template>
            </Button>
        </PopoverTrigger>
        <PopoverContent class="w-52 p-0" align="start">
            <Command>
                <CommandInput :placeholder="title" />
                <CommandList>
                    <CommandEmpty>Tiada padanan.</CommandEmpty>
                    <CommandGroup>
                        <CommandItem v-for="option in options" :key="option.value" :value="option.label"
                            @select.prevent="toggle(option.value)">
                            <div :class="cn(
                                'flex size-4 items-center justify-center rounded-sm border border-primary',
                                selectedSet.has(option.value) ? 'bg-primary text-primary-foreground' : 'opacity-50 [&_svg]:invisible',
                            )">
                                <Check class="size-3.5" />
                            </div>
                            <span class="truncate">{{ option.label }}</span>
                            <span v-if="option.count !== undefined" class="ml-auto font-mono text-xs text-muted-foreground">
                                {{ option.count }}
                            </span>
                        </CommandItem>
                    </CommandGroup>
                    <template v-if="selected.length > 0">
                        <Separator />
                        <CommandGroup>
                            <CommandItem value="__clear" class="justify-center text-center" @select.prevent="selected = []">
                                Kosongkan penapis
                            </CommandItem>
                        </CommandGroup>
                    </template>
                </CommandList>
            </Command>
        </PopoverContent>
    </Popover>
</template>
