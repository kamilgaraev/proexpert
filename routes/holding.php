<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HoldingController;

Route::get('/', [HoldingController::class, 'index'])->name('holding.home');

Route::middleware(['auth:api_landing', 'jwt.auth'])->group(function () {
    
    Route::get('/dashboard', [HoldingController::class, 'dashboard'])->name('holding.dashboard');
    
    Route::get('/organizations', [HoldingController::class, 'childOrganizations'])->name('holding.organizations');
});
