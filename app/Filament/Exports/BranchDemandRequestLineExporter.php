<?php

namespace App\Filament\Exports;

use App\Models\BranchDemandRequestLine;
use App\Support\BranchDemandLineSummary;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

/**
 * Eksport "Ringkasan Ikut Kod Design" (rujuk AllBranchDemandLinesTable) - susun ikut Kategori
 * utk BO cetak & bawa fizikal (satu kategori satu blok bersambung). Lajur Progress SENGAJA
 * kosong (bukan papar fulfillment_status sebenar) - staf isi/tanda ATAS KERTAS, bukan digital.
 *
 * modifyQuery() (orderBy 'category_name') BUKAN sekadar keperluan "susun ikut kategori" - turut
 * ELAK Filament PrepareCsvExport menulis semula ->select() jadual ni ke [id] sahaja sblm chunk
 * (laluan lalai bila TIADA orderBy langsung), yg akan pecahkan GROUP BY/selectRaw asal
 * (scopedQuery() widget - rujuk AllBranchDemandLinesTable::scopedQuery()).
 *
 * Kategori/Diminta/Saiz/Berat WAJIB baca drpd BranchDemandLineSummary::designDetailFor()
 * (bukan terus drpd $record) - rujuk dokblok method tsb: Filament ExportCsv::handle() re-fetch
 * SATU rekod via `$query->find($id)`, yg tambah WHERE id IN (...) SEBELUM GROUP BY asal, lalu
 * SUM/GROUP_CONCAT runtuh kira drpd SATU baris mentah sahaja (disahkan bug sebenar - "Diminta"
 * eksport tersilap tunjuk qty SATU cawangan, bukan jumlah SEBENAR merentasi semua cawangan).
 */
class BranchDemandRequestLineExporter extends Exporter
{
    protected static ?string $model = BranchDemandRequestLine::class;

    protected BranchDemandLineSummary $summary;

    public function __construct(Export $export, array $columnMap, array $options)
    {
        parent::__construct($export, $columnMap, $options);

        $this->summary = new BranchDemandLineSummary;
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->orderBy('category_name');
    }

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('category_name')->label('Kategori')
                ->state(fn (BranchDemandRequestLine $record, self $exporter) => $exporter->summary->designDetailFor($record)->category_name),
            ExportColumn::make('internal_code')->label('Kod Design')
                ->state(fn (BranchDemandRequestLine $record) => $record->internal_code ?: '-'),
            ExportColumn::make('item_desc')->label('Nama Item'),
            ExportColumn::make('nickname')->label('Nickname')
                ->state(fn (BranchDemandRequestLine $record, self $exporter) => $exporter->summary->nicknameFor($record) ?? ''),
            ExportColumn::make('branches')->label('Cawangan')
                ->state(fn (BranchDemandRequestLine $record, self $exporter) => implode(', ', $exporter->summary->branchBadgesFor($record))),
            ExportColumn::make('qty_requested_total')->label('Diminta')
                ->state(fn (BranchDemandRequestLine $record, self $exporter) => $exporter->summary->designDetailFor($record)->qty_requested_total),
            ExportColumn::make('sizes')->label('Saiz')
                ->state(fn (BranchDemandRequestLine $record, self $exporter) => $exporter->summary->designDetailFor($record)->sizes),
            ExportColumn::make('weights')->label('Berat (g)')
                ->state(fn (BranchDemandRequestLine $record, self $exporter) => $exporter->summary->designDetailFor($record)->weights),
            // Sengaja kosong - staf isi/tanda status progress ATAS KERTAS, bukan papar
            // fulfillment_status sebenar (rujuk dokblok kelas).
            ExportColumn::make('progress')->label('Progress')->state(fn () => ''),
            ExportColumn::make('created_at')->label('Tarikh')
                ->state(fn (BranchDemandRequestLine $record) => $record->created_at?->format('d/m/Y H:i')),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Eksport Ringkasan Ikut Kod Design selesai - '.Number::format($export->successful_rows).' '.str('baris')->plural($export->successful_rows).' dieksport.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('baris')->plural($failedRowsCount).' gagal dieksport.';
        }

        return $body;
    }
}
