<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\RateCoefficientController;

Route::apiResource('rate-coefficients', RateCoefficientController::class);

// Сюда можно будет добавить специфичные роуты для коэффициентов, если понадобятся
// например, для массового обновления или получения списка для конкретного контекста
// Route::get('rate-coefficients/applicable', [RateCoefficientController::class, 'getApplicable']); 