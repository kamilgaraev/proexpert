<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\ContractorController;

// Маршруты для Подрядчиков
// Префикс 'admin' и middleware применяются в главном файле routes/api.php
Route::apiResource('contractors', ContractorController::class);

?> 