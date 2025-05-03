<?php

use App\Http\Controllers\Api\V1\Admin\SupplierController;
use Illuminate\Support\Facades\Route;
 
Route::apiResource('suppliers', SupplierController::class); 