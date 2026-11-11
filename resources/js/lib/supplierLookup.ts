// Logik jadual Supplier Lookup yang berjalan SEPENUHNYA di pelayar (tapisan cawangan, susunan, halaman) - server hanya
// menghantar data design sekali bagi supplier + tempoh (rujuk App\Support\SupplierLookup). Fungsi tulen, tanpa Vue.

export type SortKey = 'code' | 'buy' | 'sold' | 'available' | 'percent';
export type SortDirection = 'asc' | 'desc';

// Satu design supplier: setiap cawangan -> [Total Amik/Buy, Total Sold].
export interface LookupDesign {
    code: string;
    stores: Record<string, [number, number]>;
}

// Satu baris jadual: satu design (jumlah cawangan; `store` null) ATAU satu design di satu cawangan (mod cawangan).
export interface LookupRow {
    code: string;
    store: string | null;
    buy: number;
    sold: number;
    available: number;
    percent: number;
}

export interface BranchOption {
    value: string;
    label: string;
    count: number;
}

export const PAGE_SIZE = 25;

// Bukan cawangan sebenar - tak ditawarkan sbg pilihan cawangan (masih dikira dlm jumlah tanpa tapisan).
const EXCLUDED_BRANCHES = ['SECURITY'];

function makeRow(code: string, store: string | null, buy: number, sold: number): LookupRow {
    return { code, store, buy, sold, available: buy - sold, percent: buy > 0 ? (sold / buy) * 100 : 0 };
}

// Tanpa cawangan dipilih: satu baris setiap design (jumlah semua cawangan). Dgn cawangan dipilih (mod cawangan): satu baris
// setiap design setiap cawangan yg dipilih, hanya yg ada piece diambil - jadi jadual bertambah baris.
export function rollUp(designs: LookupDesign[], branches: string[]): LookupRow[] {
    const rows: LookupRow[] = [];

    for (const design of designs) {
        if (branches.length === 0) {
            let buy = 0;
            let sold = 0;

            for (const [storeBuy, storeSold] of Object.values(design.stores)) {
                buy += storeBuy;
                sold += storeSold;
            }

            rows.push(makeRow(design.code, null, buy, sold));

            continue;
        }

        for (const branch of branches) {
            const figures = design.stores[branch];

            if (figures) {
                rows.push(makeRow(design.code, branch, figures[0], figures[1]));
            }
        }
    }

    return rows;
}

function compareText(a: string, b: string): number {
    return a < b ? -1 : a > b ? 1 : 0;
}

// Seri dipecahkan ikut kod design kemudian cawangan supaya susunan stabil. Pulangkan salinan baharu.
export function sortRows(rows: LookupRow[], key: SortKey, direction: SortDirection): LookupRow[] {
    const factor = direction === 'desc' ? -1 : 1;

    return [...rows].sort((a, b) => {
        const primary = key === 'code' ? compareText(a.code, b.code) : a[key] - b[key];

        return primary !== 0 ? primary * factor : compareText(a.code, b.code) || compareText(a.store ?? '', b.store ?? '');
    });
}

export function paginate<T>(rows: T[], page: number, pageSize: number = PAGE_SIZE): { rows: T[]; page: number; lastPage: number; total: number } {
    const total = rows.length;
    const lastPage = Math.max(1, Math.ceil(total / pageSize));
    const current = Math.min(Math.max(1, page), lastPage);

    return { rows: rows.slice((current - 1) * pageSize, current * pageSize), page: current, lastPage, total };
}

// Pilihan cawangan drpd data yg dimuat: cawangan yg ada piece diambil + bilangan design.
export function branchOptions(designs: LookupDesign[]): BranchOption[] {
    const counts = new Map<string, number>();

    for (const design of designs) {
        for (const store of Object.keys(design.stores)) {
            if (store !== '' && !EXCLUDED_BRANCHES.includes(store.toUpperCase())) {
                counts.set(store, (counts.get(store) ?? 0) + 1);
            }
        }
    }

    return [...counts.entries()]
        .sort(([a], [b]) => compareText(a, b))
        .map(([store, count]) => ({ value: store, label: store, count }));
}
