<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\SpecificationController;

// Спецификации – полный CRUD
Route::apiResource('specifications', SpecificationController::class)
    ->parameters(['specifications' => 'specification']); 