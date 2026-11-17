// Bentuk data jualan & stok cawangan pada /home (rujuk App\Support\HomeSummary\BranchSalesInsights::overview()).

export type StockTone = 'good' | 'warn' | 'bad';

export interface PeriodOption {
    value: string;
    label: string;
}

// Satu design: jualan dlm tempoh, stok cawangan dan cadangan restock (suggest = patut direstock).
export interface OverviewRow {
    code: string;
    name: string;
    description: string | null;
    category: string | null;
    image_url: string | null;
    pieces: number;
    grams: number;
    rm: number;
    stock_here: number;
    incoming: number;
    stock_tone: StockTone;
    target: number;
    need: number;
    suggest: boolean;
    stock_hq: number;
    stock_other: number;
    source: 'hq' | 'branches' | 'order';
}

export interface BranchOverview {
    period: string;
    period_label: string;
    since: string;
    synced_at: string | null;
    top: OverviewRow[];
    restock: OverviewRow[];
    restock_total: number;
}

// Warna lencana ikut token tema halaman /home (--h-*), jadi kekal sepadan dlm tema gelap & cerah.
export const STOCK_BADGE_CLASS: Record<StockTone, string> = {
    bad: 'bg-[var(--h-bad)]/20 text-[var(--h-bad)]',
    warn: 'bg-[var(--h-warn)]/20 text-[var(--h-warn)]',
    good: 'bg-[var(--h-good)]/20 text-[var(--h-good)]',
};

export const SOURCE_BADGE_CLASS: Record<OverviewRow['source'], string> = {
    hq: 'bg-[var(--h-good)]/20 text-[var(--h-good)]',
    branches: 'bg-[var(--h-gold2)]/20 text-[var(--h-gold)]',
    order: 'bg-[var(--h-line)] text-[var(--h-soft)]',
};

export function stockLabel(stock: number): string {
    return stock <= 0 ? 'Habis' : 'Stok ' + stock;
}

export function sourceLabel(row: OverviewRow): string {
    if (row.source === 'hq') return 'Ada di HQ (' + row.stock_hq + ')';
    if (row.source === 'branches') return 'Cawangan lain (' + row.stock_other + ')';

    return 'Perlu order baharu';
}
