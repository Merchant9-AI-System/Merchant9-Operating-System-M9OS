<?php

namespace App\Enums;

/** Peranan agent AI yang boleh menulis insight (rujuk AI_AGENTS_PLAN.md). */
enum InsightAgent: string
{
    case Procurement = 'procurement';
    case Finance = 'finance';
    case Marketing = 'marketing';
    case Media = 'media';
    case Ceo = 'ceo';

    public function label(): string
    {
        return match ($this) {
            self::Procurement => 'Procurement',
            self::Finance => 'Finance',
            self::Marketing => 'Marketing',
            self::Media => 'Media',
            self::Ceo => 'CEO',
        };
    }
}
