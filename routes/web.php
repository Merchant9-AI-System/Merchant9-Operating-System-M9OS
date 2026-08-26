<?php

use App\Http\Controllers\BranchDemandEntryController;
use App\Http\Controllers\JobsheetLookupController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    // return view('welcome');
    return Inertia::render('LandingPage/FirstPage');
});

// Permukaan Inertia+Vue+shadcn-vue berasingan drpd Filament, khusus staf cawangan hantar
// Branch Demand (rujuk BranchDemandEntryController) - SENGAJA tiada middleware 'auth', staf
// cawangan tak perlu login, pilih cawangan terus dlm borang (rujuk store_code pd request).
Route::middleware('auth')->group(function () {
    Route::get('/branch-demand', [BranchDemandEntryController::class, 'create'])->name('branch-demand.create');
    Route::get('/branch-demand/current-items', [BranchDemandEntryController::class, 'currentItems'])->name('branch-demand.current-items');
    Route::get('/branch-demand/search', [BranchDemandEntryController::class, 'search'])->name('branch-demand.search');
    Route::get('/branch-demand/product-image', [BranchDemandEntryController::class, 'productImage'])->name('branch-demand.product-image');
    Route::get('/branch-demand/search-website', [BranchDemandEntryController::class, 'searchWebsite'])->name('branch-demand.search-website');
    Route::get('/branch-demand/restock-suggestions', [BranchDemandEntryController::class, 'restockSuggestions'])->name('branch-demand.restock-suggestions');
    // throttle: permukaan awam tiada login - had kadar muat naik per-IP elak penyalahgunaan storan.
    Route::post('/branch-demand/upload-image', [BranchDemandEntryController::class, 'uploadImage'])->middleware('throttle:20,1')->name('branch-demand.upload-image');
    Route::post('/branch-demand', [BranchDemandEntryController::class, 'store'])->name('branch-demand.store');

    // Carian item ikut JobSheetNo (jemisys_inventory_mirror) - staf log masuk sahaja (rujuk
    // JobsheetLookupController dokblok), BERBEZA drpd Branch Demand di atas.
    Route::get('/jobsheet-lookup', [JobsheetLookupController::class, 'index'])->name('jobsheet-lookup.index');
});

// DEV SAHAJA - pratonton visual resources/views/mcp/authorize.blade.php (skrin kelulusan
// OAuth) tanpa perlu daftar client Passport sebenar/PKCE - data rekaan, klik Authorize/Cancel
// x akan berjaya (client_id palsu), tapi cukup utk semak rupa & interaktiviti.
// if (app()->environment('local')) {
//     Route::get('/_dev/oauth-authorize-preview', function () {
//         return view('mcp.authorize', [
//             'client' => (object) ['id' => 'preview-client-id', 'name' => 'Claude'],
//             'user' => (object) ['email' => 'superadmin@m9.com'],
//             'scopes' => [(object) ['description' => 'Use available MCP functionality.']],
//             'authToken' => 'preview-auth-token',
//         ]);
//     });
// }
