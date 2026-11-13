<?php

namespace App\Http\Responses;

use App\Support\HomeSummary\HomeAccess;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

/**
 * Selepas log masuk Filament, hantar pengguna ke halaman ringkasan semalam (/home) kalau peranan mereka dihidupkan
 * (rujuk HomeAccess), jika tidak ke dashboard admin seperti biasa. Kalau mereka tadi cuba membuka halaman tertentu
 * (intended URL), kekal ke situ.
 */
class HomeLoginResponse implements LoginResponse
{
    public function toResponse($request): RedirectResponse|Redirector
    {
        $default = HomeAccess::enabledFor($request->user() ?? Filament::auth()->user()) ? route('home') : Filament::getUrl();

        return redirect()->intended($default);
    }
}
