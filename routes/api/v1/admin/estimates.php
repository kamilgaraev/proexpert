<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Estimates\NormativeRateController;

/*
|--------------------------------------------------------------------------
| Старые маршруты для estimate-sections, estimate-items, estimate-versions
| и estimate-templates ПЕРЕНЕСЕНЫ в модуль BudgetEstimates
| (app/BusinessModules/Features/BudgetEstimates/routes.php)
| 
| Этот файл оставлен только для нормативных расценок (normative-rates),
| которые пока не перенесены в модуль.
|--------------------------------------------------------------------------
*/

// Нормативные расценки (нормативная база для смет)
Route::prefix('normative-rates')->group(function () {
    Route::get('/', [NormativeRateController::class, 'index']);
    Route::get('/search', [NormativeRateController::class, 'search']);
    Route::get('/collections', [NormativeRateController::class, 'collections']);
    Route::get('/collections/{collectionId}/sections', [NormativeRateController::class, 'sections']);
    Route::get('/most-used', [NormativeRateController::class, 'mostUsed']);
    Route::get('/{id}', [NormativeRateController::class, 'show']);
    Route::get('/{id}/resources', [NormativeRateController::class, 'resources']);
    Route::get('/{id}/similar', [NormativeRateController::class, 'similar']);
});

