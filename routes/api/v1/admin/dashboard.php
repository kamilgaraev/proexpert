<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\DashboardController;
use App\Http\Controllers\Api\V1\Admin\DashboardSettingsController;

Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard.index');
Route::get('/dashboard/timeseries', [DashboardController::class, 'timeseries'])->name('dashboard.timeseries');
Route::get('/dashboard/top-entities', [DashboardController::class, 'topEntities'])->name('dashboard.top-entities');
Route::get('/dashboard/history', [DashboardController::class, 'history'])->name('dashboard.history');
Route::get('/dashboard/limits', [DashboardController::class, 'limits'])->name('dashboard.limits');

// Новые endpoints для контрактов
Route::get('/dashboard/contracts/requiring-attention', [DashboardController::class, 'contractsRequiringAttention'])
    ->name('dashboard.contracts.requiring-attention');
Route::get('/dashboard/contracts/statistics', [DashboardController::class, 'contractsStatistics'])
    ->name('dashboard.contracts.statistics');
Route::get('/dashboard/contracts/top', [DashboardController::class, 'topContracts'])
    ->name('dashboard.contracts.top');
Route::get('/dashboard/recent-activity', [DashboardController::class, 'recentActivity'])
    ->name('dashboard.recent-activity');

// Статистика по заявкам (включая заявки на персонал)
Route::get('/dashboard/site-requests/statistics', [DashboardController::class, 'siteRequestsStatistics'])
    ->name('dashboard.site-requests.statistics');

// Настройки виджетов и реестр
Route::prefix('/dashboard')->group(function () {
    Route::get('/widgets', [DashboardSettingsController::class, 'widgets']);
    Route::get('/settings', [DashboardSettingsController::class, 'get']);
    Route::put('/settings', [DashboardSettingsController::class, 'put']);
    Route::delete('/settings', [DashboardSettingsController::class, 'delete']);
    Route::get('/settings/defaults', [DashboardSettingsController::class, 'defaults']);
});