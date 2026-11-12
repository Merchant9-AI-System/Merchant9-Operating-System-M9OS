// Logik jadual Supplier Lookup yang berjalan SEPENUHNYA di pelayar (tapisan cawangan, susunan, halaman) - server hanya
// menghantar data design sekali bagi supplier + tempoh (rujuk App\Support\SupplierLookup). Fungsi tulen, tanpa Vue.

export type SortKey = 'code' | 'requested' | 'buy' | 'sold' | 'available' | 'percent';
export type SortDirection = 'asc' | 'desc';

// Satu kunci susunan; susunan berbilang = senarai (yg pertama paling utama, seterusnya memecahkan seri).
export interface SortSpec {
    key: SortKey;
    dir: SortDirection;
}

// Tiada kunci dipilih = susunan asal: kod design menaik (seri terakhir sortRows) - BUKAN satu kunci 'code' yg aktif, supaya kunci pertama yg
// diklik pengguna menjadi yg paling utama (kunci 'code' ialah unik, jika ia di hadapan kunci lain tidak akan sempat berfungsi).
export const DEFAULT_SORTS: SortSpec[] = [];

// Paling banyak kunci susunan serentak (salinan SupplierLookup::MAX_SORTS di PHP).
export const MAX_SORTS = 5;

// Satu design supplier: setiap cawangan -> [Total Amik/Buy, Total Sold].
export interface LookupDesign {
    code: string;
    stores: Record<string, [number, number]>;
}

// Permintaan cawangan yg belum selesai (Branch Requested): kod design -> cawangan -> qty. Dikira segar oleh server (SupplierLookup::demandFor()),
// dihantar bersama design tetapi TIDAK sebahagian drpd data design yg dicache.
export type LookupDemand = Record<string, Record<string, number>>;

// Angka satu design di satu cawangan (utk popover pecahan pd lajur Sold / Available / Branch Requested).
export interface BranchFigures {
    store: string;
    buy: number;
    sold: number;
    available: number;
    requested: number;
}

// Satu baris jadual: satu design (jumlah cawangan; `store` null) ATAU satu design di satu cawangan (mod cawangan).
// `branches` = pecahan ikut cawangan - hanya utk baris jumlah (tanpa tapisan cawangan); kosong dlm mod cawangan (baris itu sendiri sudah satu cawangan).
export interface LookupRow {
    code: string;
    store: string | null;
    buy: number;
    sold: number;
    available: number;
    percent: number;
    requested: number;
    branches: BranchFigures[];
}

export interface BranchOption {
    value: string;
    label: string;
    count: number;
}

export const PAGE_SIZE = 25;

// Tajuk lajur jadual - SATU sumber supaya eksport (PDF/Excel) memakai tajuk yg sebijik sama. `store` hanya wujud dlm mod cawangan.
export const COLUMN_LABELS = {
    image: 'Image',
    code: 'Design Code',
    store: 'Cawangan',
    requested: 'Branch Requested',
    buy: 'Total Ambil/Buy',
    sold: 'Total Sold',
    available: 'Total Available',
    percent: 'Percentage',
} as const;

// Bukan cawangan sebenar - tak ditawarkan sbg pilihan cawangan (masih dikira dlm jumlah tanpa tapisan).
const EXCLUDED_BRANCHES = ['SECURITY'];

function makeRow(code: string, store: string | null, buy: number, sold: number, requested: number, branches: BranchFigures[] = []): LookupRow {
    return { code, store, buy, sold, available: buy - sold, percent: buy > 0 ? (sold / buy) * 100 : 0, requested, branches };
}

// Tanpa cawangan dipilih: satu baris setiap design (jumlah semua cawangan) + `branches` (pecahan ikut cawangan utk popover). Dgn cawangan
// dipilih (mod cawangan): satu baris setiap design setiap cawangan yg dipilih, hanya yg ada piece diambil - jadi jadual bertambah baris.
//
// Branch Requested (`requested`): tanpa cawangan = jumlah SEMUA cawangan yg minta (termasuk yg tak pernah ambil piece drpd supplier ini - muncul
// dlm `branches` utk popover); mod cawangan = permintaan cawangan baris itu sahaja. Salinan PHP: SupplierLookup::rollUp().
export function rollUp(designs: LookupDesign[], branches: string[], demand: LookupDemand = {}): LookupRow[] {
    const rows: LookupRow[] = [];

    for (const design of designs) {
        const requested = demand[design.code] ?? {};

        if (branches.length === 0) {
            let buy = 0;
            let sold = 0;
            let requestedTotal = 0;
            const breakdown: BranchFigures[] = [];

            // Cawangan drpd piece (server menyusun A-Z) + cawangan yg minta sahaja; disusun A-Z.
            const stores = [...new Set([...Object.keys(design.stores), ...Object.keys(requested)])].sort(compareText);

            for (const store of stores) {
                const [storeBuy, storeSold] = design.stores[store] ?? [0, 0];
                const storeRequested = requested[store] ?? 0;

                buy += storeBuy;
                sold += storeSold;
                requestedTotal += storeRequested;
                breakdown.push({ store, buy: storeBuy, sold: storeSold, available: storeBuy - storeSold, requested: storeRequested });
            }

            rows.push(makeRow(design.code, null, buy, sold, requestedTotal, breakdown));

            continue;
        }

        for (const branch of branches) {
            const figures = design.stores[branch];

            if (figures) {
                rows.push(makeRow(design.code, branch, figures[0], figures[1], requested[branch] ?? 0));
            }
        }
    }

    return rows;
}

function compareText(a: string, b: string): number {
    return a < b ? -1 : a > b ? 1 : 0;
}

// Susunan berbilang: kunci pertama dahulu, seri dipecahkan oleh kunci seterusnya, akhir sekali ikut kod design kemudian cawangan supaya
// susunan stabil. Pulangkan salinan baharu. Salinan PHP: SupplierLookup::sorted().
export function sortRows(rows: LookupRow[], sorts: SortSpec[]): LookupRow[] {
    return [...rows].sort((a, b) => {
        for (const { key, dir } of sorts) {
            const primary = key === 'code' ? compareText(a.code, b.code) : a[key] - b[key];

            if (primary !== 0) {
                return dir === 'desc' ? -primary : primary;
            }
        }

        return compareText(a.code, b.code) || compareText(a.store ?? '', b.store ?? '');
    });
}

// Klik tajuk lajur (atau baris dlm menu susunan) = lajur itu masuk/berubah dlm senarai susunan SEBAGAI kunci seterusnya: belum ada ->
// ditambah (menaik); menaik -> menurun; menurun -> dibuang. Kunci lain tak terjejas, jadi klik berturutan membina susunan berbilang.
// Kosong = susunan asal (kod design menaik). Had MAX_SORTS kunci.
export function nextSorts(current: SortSpec[], key: SortKey): SortSpec[] {
    const index = current.findIndex((spec) => spec.key === key);

    if (index === -1) {
        return current.length >= MAX_SORTS ? current : [...current, { key, dir: 'asc' }];
    }

    if (current[index].dir === 'asc') {
        return current.map((spec, i) => (i === index ? { ...spec, dir: 'desc' } : spec));
    }

    return current.filter((_, i) => i !== index);
}

// Nilai URL eksport: "percent:desc,buy:desc" (salinan parse: SupplierLookup::parseSorts()).
export function serializeSorts(sorts: SortSpec[]): string {
    return sorts.map((spec) => `${spec.key}:${spec.dir}`).join(',');
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
