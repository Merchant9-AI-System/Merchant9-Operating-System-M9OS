<?php

namespace App\Support\HomeSummary;

use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Satu seksyen ringkasan semalam pada halaman /home. Setiap seksyen tahu SIAPA yang layak melihatnya
 * (appliesTo) dan apa datanya (build) - halaman hanya memaparkan seksyen yang layak, jadi menambah
 * peranan/angka baharu = tambah satu kelas seksyen di HomeSummary::SECTIONS.
 *
 * Bentuk pulangan build():
 *  - items: senarai { label, value, hint?, tone? } (tone: default|good|warn|bad)
 *  - list:  senarai { tag, text, tone? } (cth. amaran) - pilihan
 *  - note:  mesej ringkas (cth. data tak tersedia) - pilihan
 */
interface SummarySection
{
    public function key(): string;

    public function title(): string;

    public function appliesTo(User $user): bool;

    /**
     * @return array{items: array<int, array<string, string|null>>, list?: array<int, array<string, string|null>>, note?: string}
     */
    public function build(User $user, Carbon $date): array;
}
