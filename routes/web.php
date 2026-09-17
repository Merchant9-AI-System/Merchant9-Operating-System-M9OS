<?php

use App\Http\Controllers\BranchDemandEntryController;
use App\Http\Controllers\ExpenseClaimController;
use App\Http\Controllers\JobsheetLookupController;
use App\Http\Controllers\ProductImagesController;
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
    // throttle: permukaan awam tiada login - had kadar muat naik per-IP elak penyalahgunaan storan.
    Route::post('/branch-demand/upload-image', [BranchDemandEntryController::class, 'uploadImage'])->middleware('throttle:20,1')->name('branch-demand.upload-image');
    Route::post('/branch-demand', [BranchDemandEntryController::class, 'store'])->name('branch-demand.store');

    // Carian item ikut JobSheetNo (jemisys_inventory_mirror) - staf log masuk sahaja (rujuk
    // JobsheetLookupController dokblok), BERBEZA drpd Branch Demand di atas.
    Route::get('/jobsheet-lookup', [JobsheetLookupController::class, 'index'])->name('jobsheet-lookup.index');

    // Senarai PENUH imej produk (bukan 1 thumbnail sahaja) bagi satu InternalCode - dibuka drpd
    // lajur imej ImageColumn di Filament (rujuk ProductImagesController dokblok).
    Route::get('/product-images/{internalCode}', [ProductImagesController::class, 'show'])->name('product-images.show');

    // Expense Claims - staf FINANCE urus claim BAGI PIHAK claimant (rujuk ExpenseClaimController
    // dokblok), diskop role('finance') - BUKAN lagi ownership Auth::id() (claimant != pencipta).
    Route::get('/claims', [ExpenseClaimController::class, 'index'])->name('expense-claims.index');
    Route::post('/claims', [ExpenseClaimController::class, 'store'])->name('expense-claims.store');
    Route::get('/claims/{claim}', [ExpenseClaimController::class, 'edit'])->name('expense-claims.edit');
    Route::put('/claims/{claim}/details', [ExpenseClaimController::class, 'updateDetails'])->name('expense-claims.update-details');
    Route::post('/claims/{claim}/lines', [ExpenseClaimController::class, 'storeLine'])->name('expense-claims.lines.store');
    Route::put('/claims/lines/{line}', [ExpenseClaimController::class, 'updateLine'])->name('expense-claims.lines.update');
    Route::delete('/claims/lines/{line}', [ExpenseClaimController::class, 'destroyLine'])->name('expense-claims.lines.destroy');
    Route::post('/claims/upload-receipt', [ExpenseClaimController::class, 'uploadReceipt'])->middleware('throttle:20,1')->name('expense-claims.upload-receipt');
    Route::post('/claims/{claim}/submit', [ExpenseClaimController::class, 'submit'])->name('expense-claims.submit');
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
