<?php

use App\Http\Controllers\Api\V1\Admin\ProjectController;
use Illuminate\Support\Facades\Route;
 
Route::apiResource('projects', ProjectController::class);

// Дополнительные маршруты для проекта
Route::post('projects/{project}/foremen/{user}', [ProjectController::class, 'assignForeman'])->name('projects.foremen.assign');
Route::delete('projects/{project}/foremen/{user}', [ProjectController::class, 'detachForeman'])->name('projects.foremen.detach');

// Статистика (если понадобится)
// Route::get('projects/{project}/statistics', [ProjectController::class, 'statistics'])->name('projects.statistics'); 