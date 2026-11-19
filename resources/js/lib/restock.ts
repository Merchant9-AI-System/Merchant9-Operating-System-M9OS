// Kongsi antara halaman Restock Analysis & panel butirannya: nama Cadangan, gaya (lencana/gelang/kad), format nombor & jenis data.
export const VERDICT_SOLD_OUT = 'Perlu Restock (Sold Out)';
export const VERDICT_RESTOCK = 'Perlu Restock';
export const VERDICT_OK = 'Stok Cukup';
export const VERDICT_OVERSTOCK = 'Overstock';
export const VERDICT_NO_DATA = 'Data Tak Cukup';

export interface VerdictStyle {
    short: string;
    badge: string;
    bar: string;
    ring: string;
    card: string;
    // Cip ikon (kad ringkasan) & latar lembut bila kad dipilih.
    chip: string;
    tint: string;
}

// Kelas Tailwind WAJIB tertulis penuh (tak boleh dicantum dinamik) supaya dikesan semasa build.
export const VERDICT_STYLES: Record<string, VerdictStyle> = {
    [VERDICT_SOLD_OUT]: {
        short: 'Sold Out',
        badge: 'border-transparent bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
        bar: 'bg-red-500',
        ring: 'stroke-red-500',
        card: 'border-red-200 dark:border-red-900',
        chip: 'bg-red-500/10 text-red-600 dark:text-red-400',
        tint: 'bg-red-500/5',
    },
    [VERDICT_RESTOCK]: {
        short: 'Perlu Restock',
        badge: 'border-transparent bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
        bar: 'bg-amber-500',
        ring: 'stroke-amber-500',
        card: 'border-amber-200 dark:border-amber-900',
        chip: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
        tint: 'bg-amber-500/5',
    },
    [VERDICT_OK]: {
        short: 'Stok Cukup',
        badge: 'border-transparent bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
        bar: 'bg-emerald-500',
        ring: 'stroke-emerald-500',
        card: 'border-emerald-200 dark:border-emerald-900',
        chip: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
        tint: 'bg-emerald-500/5',
    },
    [VERDICT_OVERSTOCK]: {
        short: 'Overstock',
        badge: 'border-transparent bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300',
        bar: 'bg-sky-500',
        ring: 'stroke-sky-500',
        card: 'border-sky-200 dark:border-sky-900',
        chip: 'bg-sky-500/10 text-sky-600 dark:text-sky-400',
        tint: 'bg-sky-500/5',
    },
};

const FALLBACK_STYLE: VerdictStyle = {
    short: '',
    badge: 'border-transparent bg-muted text-muted-foreground',
    bar: 'bg-muted-foreground/40',
    ring: 'stroke-muted-foreground/40',
    card: '',
    chip: 'bg-muted text-muted-foreground',
    tint: 'bg-muted/40',
};

export const styleFor = (verdict: string): VerdictStyle => VERDICT_STYLES[verdict] ?? { ...FALLBACK_STYLE, short: verdict };

// Kad ringkasan (klik = tapis Cadangan itu).
export const SUMMARY_VERDICTS = [VERDICT_SOLD_OUT, VERDICT_RESTOCK, VERDICT_OK, VERDICT_OVERSTOCK];

export function fmt(value: number | null | undefined, digits = 0): string {
    return value === null || value === undefined ? '-' : value.toLocaleString('en-MY', { maximumFractionDigits: digits });
}

export const gapClass = (gap: number | null): string =>
    gap === null ? '' : gap > 0 ? 'text-red-600 dark:text-red-400' : gap < 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400';

// Peratus stok semasa berbanding disyorkan (maks. 100) utk gelang stok.
export function coverage(stock: number, target: number | null): number {
    if (target === null || target <= 0) {
        return stock > 0 ? 100 : 0;
    }

    return Math.min(100, Math.round((stock / target) * 100));
}

export function formatDateTime(value: string | null): string {
    if (!value) {
        return '-';
    }

    const date = new Date(value.replace(' ', 'T'));

    return Number.isNaN(date.getTime()) ? value : date.toLocaleString('ms-MY', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

export interface Option {
    value: string;
    label: string;
}

export interface DesignRow {
    internal_code: string;
    store_code: string;
    category_code: string;
    category_name: string;
    description: string | null;
    vendor_name: string | null;
    image_url: string | null;
    stock: number;
    sold_1w: number;
    sold_1m: number;
    sold_3m: number;
    cover_months: number | null;
    target_stock: number;
    gap: number;
    verdict: string;
    last_sold_at: string | null;
}

export interface BucketRow {
    category_code: string;
    category_name: string;
    store_code: string;
    bucket: string;
    stock: number;
    sold_1w: number;
    sold_1m: number;
    sold_3m: number;
    cover_months: number | null;
    target_stock: number;
    gap: number;
    verdict: string;
}

export interface Page<T> {
    data: T[];
    total: number;
    page: number;
    last_page: number;
    per_page: number;
}

export interface StoreStock {
    store_code: string;
    stock: number;
    sold_1w: number;
    sold_1m: number;
    sold_3m: number;
    sold_6m: number;
    sold_1y: number;
    velocity_per_month: number;
    target_stock: number;
    gap: number;
    cover_months: number | null;
    verdict: string;
    last_sold_at: string | null;
    surplus: number;
}

export interface DesignDetail {
    code: string;
    description: string | null;
    category_code: string;
    category_name: string;
    vendor_name: string | null;
    image_url: string | null;
    selected_store: string;
    computed_at: string;
    stores: StoreStock[];
    monthly: { month: string; store: number; others: number }[];
}
