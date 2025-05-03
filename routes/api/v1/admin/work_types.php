<?php

use App\Http\Controllers\Api\V1\Admin\WorkTypeController;
use Illuminate\Support\Facades\Route;

Route::apiResource('work-types', WorkTypeController::class); 