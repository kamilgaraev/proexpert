<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\DashboardController;

Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard.index');
Route::get('/dashboard/timeseries', [DashboardController::class, 'timeseries'])->name('dashboard.timeseries');
Route::get('/dashboard/top-entities', [DashboardController::class, 'topEntities'])->name('dashboard.top-entities');
Route::get('/dashboard/history', [DashboardController::class, 'history'])->name('dashboard.history');
Route::get('/dashboard/limits', [DashboardController::class, 'limits'])->name('dashboard.limits'); 