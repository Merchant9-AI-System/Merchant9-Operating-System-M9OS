import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

// Modul /home yang dipasang utk pengguna (prop `homeModules` drpd HomeController -> HomeLayout::forUser): urutan senarai =
// urutan paparan; setiap panel menyemak sama ada modulnya dipasang dan meletakkan `order` CSS supaya susunan ikut
// tetapan pentadbir (semua panel ialah anak langsung bekas flex-col yg sama dlm Index.vue).
export interface HomeModuleMeta {
    key: string;
    title: string;
    icon: string;
    kind: 'section' | 'panel' | 'pinned';
    tab: boolean;
    scope: 'branch' | 'company' | 'flex';
}

/** Arahan HQ dsb. (jenis pinned) sentiasa di atas bar tab. */
const PINNED_ORDER = -20;

export function useHomeModules() {
    const page = usePage<{ homeModules?: HomeModuleMeta[] }>();
    const modules = computed(() => page.props.homeModules ?? []);

    function has(key: string): boolean {
        return modules.value.some((module) => module.key === key);
    }

    function orderOf(key: string): number {
        const index = modules.value.findIndex((module) => module.key === key);

        return modules.value[index]?.kind === 'pinned' ? PINNED_ORDER : index;
    }

    function orderStyle(key: string): { order: number } {
        return { order: orderOf(key) };
    }

    return { modules, has, orderOf, orderStyle };
}
