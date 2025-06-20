<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\FilterController;

Route::get('/filters/contracts', [FilterController::class, 'contractFilters'])->name('filters.contracts');
Route::get('/filters/completed-works', [FilterController::class, 'completedWorkFilters'])->name('filters.completed-works');
Route::get('/filters/quick-stats', [FilterController::class, 'quickStats'])->name('filters.quick-stats'); 