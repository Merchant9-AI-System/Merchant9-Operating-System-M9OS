<?php

namespace App\Models;

use Database\Factories\ChecklistItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Item templat Daily Checklist (rujuk migration). `store_codes` kosong = semua cawangan.
 *
 * @property array<int, string>|null $store_codes
 */
class ChecklistItem extends Model
{
    /** @use HasFactory<ChecklistItemFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['store_codes' => 'array', 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    /** @return HasMany<ChecklistCompletion, $this> */
    public function completions(): HasMany
    {
        return $this->hasMany(ChecklistCompletion::class);
    }

    /** @param  Builder<ChecklistItem>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function appliesToStore(string $store): bool
    {
        $codes = array_map(fn ($code) => strtoupper(trim((string) $code)), $this->store_codes ?? []);

        return $codes === [] || in_array(strtoupper(trim($store)), $codes, true);
    }

    /** "HH:MM" atau null. */
    public function dueLabel(): ?string
    {
        return $this->due_time ? substr((string) $this->due_time, 0, 5) : null;
    }
}
