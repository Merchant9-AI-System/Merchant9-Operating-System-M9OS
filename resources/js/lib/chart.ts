// Kongsi antara komponen carta (Unovis): warna siri & templat tooltip. Warna siri = warna Cadangan sedia ada supaya carta & lencana sepadan.
export const CHART_COLORS = {
    soldOut: '#ef4444',
    restock: '#f59e0b',
    stock: '#0ea5e9',
    sold: '#f59e0b',
    target: '#94a3b8',
} as const;

export interface ChartSeries {
    key: string;
    label: string;
    color: string;
}

const escapeHtml = (value: string): string =>
    value.replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char] as string);

// Templat tooltip (Unovis memerlukan rentetan HTML). Semua teks dilepaskan (escape) kerana label datang drpd data.
export function tooltipHtml(title: string, rows: { label: string; color: string; value: string }[]): string {
    const items = rows
        .map(
            (row) =>
                `<div class="flex items-center justify-between gap-4"><span class="flex items-center gap-1.5"><span class="inline-block size-2 rounded-sm" style="background:${escapeHtml(row.color)}"></span>${escapeHtml(row.label)}</span><span class="font-medium tabular-nums">${escapeHtml(row.value)}</span></div>`,
        )
        .join('');

    return `<div class="flex min-w-32 flex-col gap-1 text-xs"><div class="font-medium">${escapeHtml(title)}</div>${items}</div>`;
}
