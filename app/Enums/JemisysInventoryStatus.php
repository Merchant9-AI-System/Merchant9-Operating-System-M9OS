<?php

namespace App\Enums;

/**
 * Legend rasmi kod Status (varchar(1)) JEMiSys TblInventory - disahkan drpd source code
 * stored procedure PushSalesToDFSServer (silang semak ReportSplitItemStatus/UpdateRMIssueStatus).
 * 'I' hanya muncul pada InventoryCode prefix BAR/SPL/GB0 (bullion/raw material).
 */
enum JemisysInventoryStatus: string
{
    case Sold = '0';
    case Available = '1';
    case PurchaseConsignReturn = '2';
    case ConsignSales = '3';
    case Transit = '5';
    case StockOut = '6';
    case LoanTransit = '7';
    case LoanReturnTransit = '8';
    case SoldClosed = '9';
    case DisassemblyFromFg = 'I';

    public function label(): string
    {
        return match ($this) {
            self::Sold, self::SoldClosed => 'Sold',
            self::Available => 'Available',
            self::PurchaseConsignReturn => 'Purchase/Consign Return',
            self::ConsignSales => 'Consign Sales',
            self::Transit => 'Transit',
            self::StockOut => 'Stock Out',
            self::LoanTransit => 'Loan Transit',
            self::LoanReturnTransit => 'Loan Return Transit',
            self::DisassemblyFromFg => 'Disassembly from FG',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Available => 'success',
            self::Sold, self::SoldClosed => 'gray',
            self::Transit, self::LoanTransit, self::LoanReturnTransit => 'warning',
            self::StockOut => 'danger',
            self::PurchaseConsignReturn, self::ConsignSales, self::DisassemblyFromFg => 'info',
        };
    }

    public static function labelFor(?string $code): string
    {
        $code = trim((string) $code);

        return self::tryFrom($code)?->label() ?: ($code !== '' ? $code : '-');
    }

    public static function colorFor(?string $code): string
    {
        return self::tryFrom(trim((string) $code))?->color() ?? 'gray';
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->label().' ('.$case->value.')'])->all();
    }
}
