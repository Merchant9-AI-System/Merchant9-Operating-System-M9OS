// Susunan berbilang generik utk jadual kecil sebelah klien (cth. senarai piece pd halaman butiran design). Corak sama dgn
// lib/supplierLookup.ts (nextSorts/sortRows): klik tajuk = lajur ditambah (menaik) -> menurun -> dibuang; kunci lain kekal, jadi klik
// berturutan membina susunan berbilang. Kosong = susunan asal (urutan data).
export type SortDir = 'asc' | 'desc';

export interface Sort<K extends string> {
    key: K;
    dir: SortDir;
}

// Paling banyak kunci serentak.
export const MAX_MULTI_SORTS = 5;

export function nextSort<K extends string>(current: Sort<K>[], key: K, max: number = MAX_MULTI_SORTS): Sort<K>[] {
    const index = current.findIndex((spec) => spec.key === key);

    if (index === -1) {
        return current.length >= max ? current : [...current, { key, dir: 'asc' }];
    }

    if (current[index].dir === 'asc') {
        return current.map((spec, i) => (i === index ? { ...spec, dir: 'desc' } : spec));
    }

    return current.filter((_, i) => i !== index);
}

type SortValue = string | number | null;

// Susun salinan baharu: kunci pertama dahulu, seri dipecahkan kunci seterusnya, akhir sekali urutan asal (stabil). Nilai null/kosong
// SENTIASA di hujung (menaik atau menurun). `valueOf` memulangkan nilai boleh banding (nombor, atau teks - cth. tarikh ISO).
export function sortByKeys<T, K extends string>(rows: T[], sorts: Sort<K>[], valueOf: (row: T, key: K) => SortValue): T[] {
    if (sorts.length === 0) {
        return rows;
    }

    return rows
        .map((row, index) => ({ row, index }))
        .sort((a, b) => {
            for (const { key, dir } of sorts) {
                const left = valueOf(a.row, key);
                const right = valueOf(b.row, key);

                if (left === right) {
                    continue;
                }

                if (left === null || left === '') {
                    return 1;
                }

                if (right === null || right === '') {
                    return -1;
                }

                const primary = typeof left === 'number' && typeof right === 'number' ? left - right : String(left).localeCompare(String(right), 'en', { numeric: true });

                if (primary !== 0) {
                    return dir === 'desc' ? -primary : primary;
                }
            }

            return a.index - b.index;
        })
        .map(({ row }) => row);
}

export function sortDirectionOf<K extends string>(sorts: Sort<K>[], key: K): SortDir | null {
    return sorts.find((spec) => spec.key === key)?.dir ?? null;
}

// Nombor keutamaan (1, 2, 3...) hanya bila lebih drpd satu lajur disusun.
export function sortPriorityOf<K extends string>(sorts: Sort<K>[], key: K): number | null {
    const index = sorts.findIndex((spec) => spec.key === key);

    return sorts.length > 1 && index !== -1 ? index + 1 : null;
}
