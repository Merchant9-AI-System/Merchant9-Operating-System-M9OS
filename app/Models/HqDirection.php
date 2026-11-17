<?php

namespace App\Models;

use App\Support\BranchActivityCalculator;
use Database\Factories\HqDirectionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Arahan HQ kepada cawangan (M04 HQ Direction). Dicipta HQ/CEO melalui widget Filament (HqDirectionsWidget),
 * dipaparkan pd /home ketua cawangan sepanjang tempoh berkuat kuasa - rujuk App\Support\HomeSummary\HqDirectionFeed.
 *
 * Peraturan paparan utk ketua cawangan: kelihatan selagi BELUM diakui ("Faham"); selepas diakui ia kekal kelihatan
 * pada hari akuan sahaja (tarikh waktu Malaysia), kemudian hilang.
 * `body` ialah HTML rich editor - WAJIB disanitasi (htmlBody()) sebelum dipaparkan di mana-mana.
 */
class HqDirection extends Model
{
    /** @use HasFactory<HqDirectionFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    /** "Hari ini" bagi arahan sentiasa ikut waktu Malaysia (config app.timezone ialah UTC). */
    public const TIMEZONE = 'Asia/Kuala_Lumpur';

    protected $fillable = ['title', 'body', 'effective_from', 'effective_to', 'all_branches', 'created_by_id', 'created_by_name'];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'all_branches' => 'boolean',
    ];

    public static function today(): Carbon
    {
        return Carbon::now(self::TIMEZONE)->startOfDay();
    }

    public function branches(): HasMany
    {
        return $this->hasMany(HqDirectionBranch::class);
    }

    public function acknowledgements(): HasMany
    {
        return $this->hasMany(HqDirectionAcknowledgement::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /** Arahan yang berkuat kuasa pada tarikh itu: bermula <= tarikh dan (tiada tarikh tamat atau tamat >= tarikh). */
    public function scopeActiveOn(Builder $query, Carbon|string $date): Builder
    {
        $day = Carbon::parse($date)->toDateString();

        return $query->whereDate('effective_from', '<=', $day)
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day));
    }

    /** Arahan yang menyasarkan cawangan ini: semua cawangan, atau cawangan ini dipilih. */
    public function scopeForStore(Builder $query, string $store): Builder
    {
        $store = self::normalizeStore($store);

        return $query->where(fn (Builder $q) => $q->where('all_branches', true)
            ->orWhereHas('branches', fn (Builder $b) => $b->where('store_code', $store)));
    }

    public static function normalizeStore(string $store): string
    {
        return strtoupper(trim($store));
    }

    public function isActiveOn(Carbon $date): bool
    {
        return $this->effective_from->lte($date) && ($this->effective_to === null || $this->effective_to->gte($date));
    }

    /**
     * Kod cawangan sasaran: semua cawangan jualan (jika all_branches) atau cawangan yang dipilih.
     *
     * @return array<int, string>
     */
    public function targetStores(): array
    {
        $codes = $this->all_branches
            ? BranchActivityCalculator::branches()
            : $this->branches->pluck('store_code')->all();

        return collect($codes)->map(fn ($c) => self::normalizeStore((string) $c))->unique()->sort()->values()->all();
    }

    /** HTML yang selamat dipaparkan (rich editor menghantar HTML mentah - rujuk dokumentasi keselamatan Filament). */
    public function htmlBody(): string
    {
        return (string) str($this->body)->sanitizeHtml();
    }

    /**
     * Cipta arahan + cawangan sasaran. `branches` diabaikan bila `all_branches` = true.
     *
     * @param  array{title: string, body: string, effective_from: string, effective_to?: string|null, all_branches?: bool, branches?: array<int, string>}  $data
     */
    public static function createWithBranches(array $data, User $author): self
    {
        $direction = static::create([
            'title' => $data['title'],
            'body' => $data['body'],
            'effective_from' => $data['effective_from'],
            'effective_to' => $data['effective_to'] ?? null,
            'all_branches' => (bool) ($data['all_branches'] ?? false),
            'created_by_id' => $author->id,
            'created_by_name' => $author->name,
        ]);

        $direction->syncBranches($direction->all_branches ? [] : ($data['branches'] ?? []));

        return $direction;
    }

    /** @param  array<int, string>  $codes */
    public function syncBranches(array $codes): void
    {
        $codes = collect($codes)->map(fn ($c) => self::normalizeStore((string) $c))->filter()->unique()->values();

        $this->branches()->whereNotIn('store_code', $codes)->delete();

        foreach ($codes as $code) {
            $this->branches()->firstOrCreate(['store_code' => $code]);
        }
    }

    /** Tanda "Faham" bagi cawangan ini - idempotent (akuan pertama kekal, tak ditimpa). */
    public function acknowledge(string $store, User $user): HqDirectionAcknowledgement
    {
        return $this->acknowledgements()->firstOrCreate(
            ['store_code' => self::normalizeStore($store)],
            ['acknowledged_by_id' => $user->id, 'acknowledged_by_name' => $user->name, 'acknowledged_at' => now()],
        );
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll();
    }
}
