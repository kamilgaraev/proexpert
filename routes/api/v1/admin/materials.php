<?php

use App\Http\Controllers\Api\V1\Admin\MaterialController;
use Illuminate\Support\Facades\Route;
 
Route::apiResource('materials', MaterialController::class); 