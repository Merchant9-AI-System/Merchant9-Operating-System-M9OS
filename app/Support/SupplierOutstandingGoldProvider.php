<?php

namespace App\Support;

use App\Models\PhysicalGoldReportLine;

/**
 * Baki "Outstanding Gold Due to Suppliers" - PALING BARU DIKETAHUI BAGI SETIAP VENDOR (bukan
 * sekadar baris drpd SATU laporan plaing baru) - data ni SENDIRI dah tersimpan lokal
 * (physical_gold_report_lines), BUKAN sumber luar/live (beza drpd UsedGoldBalanceProvider/
 * UsedGoldTransitProvider), tiada panggilan rangkaian/DB luar langsung.
 *
 * SENGAJA "terkini PER VENDOR" (bukan "semua baris drpd 1 laporan terpilih") - disahkan sebenar:
 * vendor berlainan (cth. CJ, CT) direkod pd LAPORAN BERLAINAN (bukan sentiasa sama-sama dlm 1
 * laporan) - kalau ambil cuma 1 laporan (walaupun "laporan terkini yg ada data"), vendor lama
 * yg TIADA dlm laporan terkini tu (cth. CT, kali terakhir direkod laporan lebih lama) akan
 * TERCICIR sepenuhnya drpd hasil tarikan - staf kena tahu SEMUA vendor yg pernah ada baki, bukan
 * hanya vendor dlm laporan yg kebetulan disentuh terakhir.
 */
class SupplierOutstandingGoldProvider
{
    /**
     * Satu baris SAHAJA per vendor - ambil rekod PALING BARU (report_date menurun) bagi setiap
     * vendor_code unik. Null bermaksud TIADA langsung laporan lampau yg pernah isi seksyen ni.
     *
     * @return array<int, array{vendor_code: ?string, payable_gross_weight: ?float, receivable_gross_weight: ?float}>|null
     */
    public static function fromLatest(): ?array
    {
        $lines = PhysicalGoldReportLine::query()
            ->whereHas('category', fn ($q) => $q->where('code', 'SUPPLIER_OUTSTANDING'))
            ->whereNotNull('vendor_code')
            ->join('physical_gold_reports', 'physical_gold_reports.id', '=', 'physical_gold_report_lines.physical_gold_report_id')
            ->orderByDesc('physical_gold_reports.report_date')
            ->orderByDesc('physical_gold_reports.id')
            ->get(['physical_gold_report_lines.*']);

        if ($lines->isEmpty()) {
            return null;
        }

        // unique() kekalkan kemunculan PERTAMA bagi setiap kunci - sbb koleksi dah disusun
        // menurun ikut tarikh, kemunculan pertama tiap vendor = rekod PALING BARU utk vendor tu.
        return $lines
            ->unique(fn ($line) => trim((string) $line->vendor_code))
            ->map(fn ($line) => [
                'vendor_code' => $line->vendor_code,
                // Fallback ke pure_weight bila gross_weight kosong - biasanya x akan tercetus utk
                // baris SUPPLIER_OUTSTANDING yg disimpan cara normal (pure_weight SENTIASA
                // DIKIRA drpd gross via PhysicalGoldReportLine::booted(), bukan nilai bebas -
                // kalau gross kosong, pure turut kosong) - cuma jaring keselamatan utk rekod lama/
                // luar biasa (cth. diedit terus DB, atau kategori pernah bertukar). Nilai pure
                // dipakai TERUS (bukan diterbalikkan bahagi faktor ketulenan) - anggaran sahaja
                // bagi kes jarang ni, bukan penukaran gross yg tepat.
                'payable_gross_weight' => $line->payable_gross_weight ?? $line->payable_pure_weight,
                'receivable_gross_weight' => $line->receivable_gross_weight ?? $line->receivable_pure_weight,
            ])
            ->values()
            ->all();
    }
}
