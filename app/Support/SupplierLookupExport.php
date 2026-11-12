<?php

namespace App\Support;

use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithDrawings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Excel (.xlsx) Supplier Lookup dgn GAMBAR SEBENAR terbenam (bukan URL): satu baris tajuk, baris tajuk lajur (sebijik COLUMN_LABELS jadual),
 * kemudian semua baris. Sel Design Code = kod + nickname (dua baris, seperti sel jadual), Percentage = nombor berformat 0.0% (boleh
 * disusun/ditapis di Excel). Gambar ialah thumbnail JPEG kecil dr RemoteThumbnails, dilekat pd sel lajur Image (baris yg gambarnya tiada/
 * belum sempat dimuat dibiarkan kosong).
 *
 * Susunan lajur: Image, Design Code, [Cawangan], Total Ambil/Buy, Total Sold, Total Available, Percentage.
 */
final class SupplierLookupExport implements FromArray, WithColumnFormatting, WithColumnWidths, WithDrawings, WithStyles, WithTitle
{
    use Exportable;

    /** Baris pertama data (1 = tajuk, 2 = tajuk lajur). */
    public const FIRST_DATA_ROW = 3;

    /** Tinggi baris (point) bagi baris yg ada gambar, & saiz gambar (px) yg muat di dalamnya. */
    private const IMAGE_ROW_HEIGHT = 40;

    private const IMAGE_HEIGHT_PX = 44;

    /**
     * @param  list<array{code: string, store: ?string, buy: int, sold: int, available: int, percent: float, nickname: ?string, image_url: ?string, image_reference: bool}>  $rows
     * @param  array<string, string>  $thumbnails  url => laluan fail JPEG
     */
    public function __construct(
        private readonly string $heading,
        private readonly array $rows,
        private readonly bool $branchMode,
        private readonly array $thumbnails,
    ) {}

    /** @return list<list<mixed>> */
    public function array(): array
    {
        $labels = SupplierLookup::COLUMN_LABELS;

        $header = [$labels['image'], $labels['code']];

        if ($this->branchMode) {
            $header[] = $labels['store'];
        }

        array_push($header, $labels['buy'], $labels['sold'], $labels['available'], $labels['percent']);

        $data = array_map(function (array $row): array {
            $values = ['', trim($row['code'].($row['nickname'] ? "\n".$row['nickname'] : ''))];

            if ($this->branchMode) {
                $values[] = (string) $row['store'];
            }

            return [...$values, $row['buy'], $row['sold'], $row['available'], round($row['percent'], 1)];
        }, $this->rows);

        return [[$this->heading], $header, ...$data];
    }

    /** @return array<string, int> */
    public function columnWidths(): array
    {
        return $this->branchMode
            ? ['A' => 10, 'B' => 38, 'C' => 16, 'D' => 18, 'E' => 14, 'F' => 18, 'G' => 14]
            : ['A' => 10, 'B' => 38, 'C' => 18, 'D' => 14, 'E' => 18, 'F' => 14];
    }

    /** @return array<string, string> */
    public function columnFormats(): array
    {
        return [$this->branchMode ? 'G' : 'F' => '0.0"%"'];
    }

    /** @return array<int, mixed> */
    public function styles(Worksheet $sheet): array
    {
        $lastRow = self::FIRST_DATA_ROW + count($this->rows) - 1;

        if ($lastRow >= self::FIRST_DATA_ROW) {
            $range = 'A'.self::FIRST_DATA_ROW.':'.$sheet->getHighestColumn().$lastRow;
            $sheet->getStyle($range)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getStyle('B'.self::FIRST_DATA_ROW.':B'.$lastRow)->getAlignment()->setWrapText(true);
        }

        foreach ($this->rows as $index => $row) {
            if ($this->thumbnailFor($row) !== null) {
                $sheet->getRowDimension(self::FIRST_DATA_ROW + $index)->setRowHeight(self::IMAGE_ROW_HEIGHT);
            }
        }

        return [
            1 => ['font' => ['bold' => true]],
            2 => ['font' => ['bold' => true]],
        ];
    }

    /** @return list<Drawing> */
    public function drawings(): array
    {
        $drawings = [];

        foreach ($this->rows as $index => $row) {
            $path = $this->thumbnailFor($row);

            if ($path === null) {
                continue;
            }

            $drawing = new Drawing;
            $drawing->setName($row['code']);
            $drawing->setDescription($row['code']);
            $drawing->setPath($path);
            $drawing->setHeight(self::IMAGE_HEIGHT_PX);
            $drawing->setCoordinates('A'.(self::FIRST_DATA_ROW + $index));
            $drawing->setOffsetX(4);
            $drawing->setOffsetY(4);

            $drawings[] = $drawing;
        }

        return $drawings;
    }

    public function title(): string
    {
        return 'Supplier Lookup';
    }

    /** @param  array<string, mixed>  $row */
    private function thumbnailFor(array $row): ?string
    {
        return filled($row['image_url']) ? ($this->thumbnails[$row['image_url']] ?? null) : null;
    }
}
