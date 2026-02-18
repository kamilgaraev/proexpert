<?php

use App\Http\Controllers\Api\V1\Mobile\ProjectController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:api_mobile')->group(function () {
    Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
});
