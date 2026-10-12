<?php

namespace App\Enums;

enum InsightSeverity: string
{
    case High = 'high';
    case Medium = 'medium';
    case Info = 'info';

    public function label(): string
    {
        return match ($this) {
            self::High => 'Tinggi',
            self::Medium => 'Sederhana',
            self::Info => 'Maklumat',
        };
    }
}
