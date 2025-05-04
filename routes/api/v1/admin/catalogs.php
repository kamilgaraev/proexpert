<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\MaterialController;
use App\Http\Controllers\Api\V1\Admin\WorkTypeController;
use App\Http\Controllers\Api\V1\Admin\SupplierController;

/*
|--------------------------------------------------------------------------
| Admin API V1 Catalog Routes
|--------------------------------------------------------------------------
|
| Маршруты для управления справочниками (Материалы, Виды работ, Поставщики).
|
*/

// Группа уже защищена middleware в RouteServiceProvider

Route::apiResource('materials', MaterialController::class);
Route::apiResource('work-types', WorkTypeController::class);
Route::apiResource('suppliers', SupplierController::class); 