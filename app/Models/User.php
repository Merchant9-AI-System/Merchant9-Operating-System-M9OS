<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Jemisys\Store;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'username', 'password', 'store_code'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, LogsActivity, Notifiable;

    // SENGAJA TIADA Laravel\Passport\HasApiTokens/Contracts\OAuthenticatable di sini - disahkan
    // (baca terus source vendor/laravel/passport, bukan teka) guard 'passport' (TokenGuard::
    // user(), rujuk routes/ai.php auth:sanctum,api) HANYA panggil withAccessToken() pd model -
    // Sanctum::HasApiTokens (di atas) SUDAH sediakan withAccessToken()/currentAccessToken()/
    // tokenCan()/tokenCant() generik yg serasi (token Passport implement Contracts\
    // ScopeAuthorizable::can()/cant(), padan panggilan Sanctum). Menambah trait Passport
    // pula CIPTA percanggahan PROPERTY $accessToken antara dua trait (disahkan cubaan
    // sebenar - Fatal Error "define the same property... considered incompatible") - method-
    // level insteadof/as TAK BOLEH selesaikan percanggahan property. oauthApps()/
    // getProviderName() (kaedah Passport tambahan) hanya dipakai fitur "urus app OAuth
    // pengguna" (ClientRepository) yg TIDAK dibina di sini - x diperlukan.

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(config('filament-shield.super_admin.name'));
    }

    /** Cawangan tempat user ditempatkan (null utk HQ/CEO/super_admin - tak terikat satu cawangan). */
    public function store()
    {
        return $this->belongsTo(Store::class, 'store_code', 'StoreCode');
    }

    /** Kecualikan super_admin drpd senarai boleh dipilih utk notifikasi (cth. picker "Notify Back Office"). */
    public function scopeNotifiable($query)
    {
        return $query->whereDoesntHave('roles', fn ($q) => $q->where('name', config('filament-shield.super_admin.name')));
    }
}
