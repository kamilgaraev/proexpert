<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\CompletedWorkController;

Route::apiResource('completed-works', CompletedWorkController::class); 