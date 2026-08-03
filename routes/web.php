<?php

use App\Http\Controllers\BranchDemandEntryController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Permukaan Inertia+Vue+shadcn-vue berasingan drpd Filament, khusus staf cawangan hantar
// Branch Demand (rujuk BranchDemandEntryController) - SENGAJA tiada middleware 'auth', staf
// cawangan tak perlu login, pilih cawangan terus dlm borang (rujuk store_code pd request).
Route::get('/branch-demand', [BranchDemandEntryController::class, 'create'])->name('branch-demand.create');
Route::get('/branch-demand/requests', [BranchDemandEntryController::class, 'requests'])->name('branch-demand.requests');
Route::get('/branch-demand/search', [BranchDemandEntryController::class, 'search'])->name('branch-demand.search');
Route::get('/branch-demand/restock-suggestions', [BranchDemandEntryController::class, 'restockSuggestions'])->name('branch-demand.restock-suggestions');
Route::post('/branch-demand', [BranchDemandEntryController::class, 'store'])->name('branch-demand.store');
