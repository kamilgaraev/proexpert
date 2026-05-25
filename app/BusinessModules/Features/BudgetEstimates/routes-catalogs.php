<?php

use Illuminate\Support\Facades\Route;
use App\BusinessModules\Features\BudgetEstimates\Http\Controllers\MachineryController;
use App\BusinessModules\Features\BudgetEstimates\Http\Controllers\LaborResourceController;
use App\Support\Routing\AdminRouteStack;

/*
|--------------------------------------------------------------------------
| Budget Estimates - Resource Catalogs Routes
|--------------------------------------------------------------------------
|
| Маршруты для управления справочниками ресурсов:
| - Механизмы (machinery)
| - Трудовые ресурсы (labor_resources)
|
*/

Route::prefix('api/v1/admin')
    ->name('admin.')
    ->middleware(AdminRouteStack::middleware(['budget-estimates.active']))
    ->group(function () {
        
        // ============================================
        // СПРАВОЧНИК МЕХАНИЗМОВ
        // ============================================
        Route::prefix('machinery')->name('machinery.')->group(function () {
            Route::get('/', [MachineryController::class, 'index'])->name('index');
            Route::post('/', [MachineryController::class, 'store'])->name('store');
            Route::get('/categories', [MachineryController::class, 'categories'])->name('categories');
            Route::get('/statistics', [MachineryController::class, 'statistics'])->name('statistics');
            Route::get('/autocomplete', [MachineryController::class, 'autocomplete'])->name('autocomplete');
            Route::get('/{id}', [MachineryController::class, 'show'])->name('show');
            Route::put('/{id}', [MachineryController::class, 'update'])->name('update');
            Route::delete('/{id}', [MachineryController::class, 'destroy'])->name('destroy');
        });
        
        // ============================================
        // СПРАВОЧНИК ТРУДОВЫХ РЕСУРСОВ
        // ============================================
        Route::prefix('labor-resources')->name('labor_resources.')->group(function () {
            Route::get('/', [LaborResourceController::class, 'index'])->name('index');
            Route::post('/', [LaborResourceController::class, 'store'])->name('store');
            Route::get('/professions', [LaborResourceController::class, 'professions'])->name('professions');
            Route::get('/categories', [LaborResourceController::class, 'categories'])->name('categories');
            Route::get('/statistics', [LaborResourceController::class, 'statistics'])->name('statistics');
            Route::get('/autocomplete', [LaborResourceController::class, 'autocomplete'])->name('autocomplete');
            Route::get('/{id}', [LaborResourceController::class, 'show'])->name('show');
            Route::put('/{id}', [LaborResourceController::class, 'update'])->name('update');
            Route::delete('/{id}', [LaborResourceController::class, 'destroy'])->name('destroy');
        });
    });
