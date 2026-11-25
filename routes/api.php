<?php

use App\Http\Controllers\MemberLookupController;
use App\Http\Controllers\SsoUserInfoController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:api');

// Dipanggil oleh QMS kiosk (apps/desti) - kenal pasti ahli JEMiSys sedia ada drpd NRIC/telefon
// semasa isu tiket. Token servis Sanctum skop 'members:lookup' sahaja (rujuk
// App\Console\Commands\IssueQmsKioskToken) - throttle lebih ketat drpd 'mcp' (rujuk
// AppServiceProvider::boot()), carian sempit satu ahli, bukan API pukal.
// SSO: maklumat akaun bagi sistem lain yg log masuk melalui m9os (token Passport berskop `sso`) - SsoUserInfoController.
Route::get('/sso/userinfo', SsoUserInfoController::class)->middleware(['throttle:60,1', 'auth:api'])->name('sso.userinfo');

Route::post('/internal/members/lookup', MemberLookupController::class)
    ->middleware(['throttle:member-lookup', 'auth:sanctum', 'abilities:members:lookup']);
