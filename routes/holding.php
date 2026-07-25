<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HoldingController;

Route::get('/', [HoldingController::class, 'index'])->name('holding.home');

Route::middleware(['auth.web:lk', 'auth.session', 'organization.context', 'interface:lk'])->group(function () {
    
    Route::get('/dashboard', [HoldingController::class, 'dashboard'])->name('holding.dashboard');
    
    Route::get('/organizations', [HoldingController::class, 'childOrganizations'])->name('holding.organizations');
});
