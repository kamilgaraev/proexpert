<?php

use App\Http\Controllers\Api\V1\Schedule\ProjectScheduleController;
use Illuminate\Support\Facades\Route;

// Маршруты для системы графика работ (Gantt Chart)
Route::prefix('schedules')->group(function () {
    
    // ВАЖНО: Статические роуты должны быть ДО параметрических!
    
    // Дополнительные эндпоинты (статические роуты)
    Route::get('templates', [ProjectScheduleController::class, 'templates'])
        ->name('schedules.templates');
    Route::post('from-template', [ProjectScheduleController::class, 'createFromTemplate'])
        ->name('schedules.from-template');
    Route::get('statistics', [ProjectScheduleController::class, 'statistics'])
        ->name('schedules.statistics');
    Route::get('overdue', [ProjectScheduleController::class, 'overdue'])
        ->name('schedules.overdue');
    Route::get('recent', [ProjectScheduleController::class, 'recent'])
        ->name('schedules.recent');
    Route::get('resource-conflicts', [ProjectScheduleController::class, 'allResourceConflicts'])
        ->name('schedules.all-resource-conflicts');
    
    // Основные CRUD операции для графиков проектов (параметрические роуты)
    Route::apiResource('/', ProjectScheduleController::class)->parameters([
        '' => 'schedule'
    ])->names([
        'index' => 'schedules.index',
        'store' => 'schedules.store', 
        'show' => 'schedules.show',
        'update' => 'schedules.update',
        'destroy' => 'schedules.destroy',
    ]);
    
    // Специальные маршруты для конкретных графиков
    Route::prefix('{schedule}')->group(function () {
        // Критический путь
        Route::post('critical-path', [ProjectScheduleController::class, 'calculateCriticalPath'])
            ->name('schedules.critical-path');
        
        // Базовые планы
        Route::post('baseline', [ProjectScheduleController::class, 'saveBaseline'])
            ->name('schedules.save-baseline');
        Route::delete('baseline', [ProjectScheduleController::class, 'clearBaseline'])
            ->name('schedules.clear-baseline');
            
        // Задачи и зависимости
        Route::get('tasks', [ProjectScheduleController::class, 'tasks'])
            ->name('schedules.tasks');
        Route::post('tasks', [ProjectScheduleController::class, 'storeTask'])
            ->name('schedules.tasks.store');
        Route::get('dependencies', [ProjectScheduleController::class, 'dependencies'])
            ->name('schedules.dependencies');
        Route::post('dependencies', [ProjectScheduleController::class, 'storeDependency'])
            ->name('schedules.dependencies.store');
        Route::get('resource-conflicts', [ProjectScheduleController::class, 'resourceConflicts'])
            ->name('schedules.resource-conflicts');
    });
});