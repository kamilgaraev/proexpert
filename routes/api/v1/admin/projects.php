<?php

use App\Http\Controllers\Api\V1\Admin\ProjectController;
use App\Http\Controllers\Api\V1\Admin\ProjectOrganizationController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\ProjectChildWorksController;
 
// Projects CRUD с детальными правами
Route::get('projects', [ProjectController::class, 'index'])
    ->middleware('authorize:admin.projects.view')
    ->name('projects.index');
Route::post('projects', [ProjectController::class, 'store'])
    ->middleware('authorize:admin.projects.edit')
    ->name('projects.store');

// Получить доступные категории затрат для проектов (должно быть ПЕРЕД projects/{project})
Route::get('/projects/available-cost-categories', [ProjectController::class, 'getAvailableCostCategories'])->name('projects.cost-categories');

Route::get('projects/{project}', [ProjectController::class, 'show'])
    ->middleware('authorize:admin.projects.view')
    ->name('projects.show');
Route::put('projects/{project}', [ProjectController::class, 'update'])
    ->middleware('authorize:admin.projects.edit')
    ->name('projects.update');
Route::patch('projects/{project}', [ProjectController::class, 'update'])
    ->middleware('authorize:admin.projects.edit')
    ->name('projects.patch');
Route::delete('projects/{project}', [ProjectController::class, 'destroy'])
    ->middleware('authorize:admin.projects.edit')
    ->name('projects.destroy');

// Дополнительные маршруты для проекта
Route::post('/projects/{projectId}/foremen/{userId}', [ProjectController::class, 'assignForeman'])->name('projects.foremen.assign');
Route::delete('/projects/{projectId}/foremen/{userId}', [ProjectController::class, 'detachForeman'])->name('projects.foremen.detach');

// Получить статистику по проекту
Route::get('/projects/{id}/statistics', [ProjectController::class, 'statistics'])
    ->middleware('authorize:admin.projects.analytics')
    ->name('projects.statistics');

// Получить материалы проекта
Route::get('/projects/{id}/materials', [ProjectController::class, 'getProjectMaterials'])->name('projects.materials');

// Получить типы работ проекта
Route::get('/projects/{id}/work-types', [ProjectController::class, 'getProjectWorkTypes'])->name('projects.workTypes');

// Получить организации проекта
Route::get('/projects/{id}/organizations', [ProjectOrganizationController::class, 'index'])->name('projects.organizations.index');
Route::post('/projects/{projectId}/organizations/{organizationId}', [ProjectOrganizationController::class, 'attach'])->name('projects.organizations.attach');
Route::delete('/projects/{projectId}/organizations/{organizationId}', [ProjectOrganizationController::class, 'detach'])->name('projects.organizations.detach');

// Получить детализированные работы дочерних организаций
Route::get('/projects/{id}/child-works', [ProjectChildWorksController::class, 'index'])->name('projects.child-works.index');

// Статистика (если понадобится)
// Route::get('projects/{project}/statistics', [ProjectController::class, 'statistics'])->name('projects.statistics'); 

Route::get('/projects/{id}/full', [ProjectController::class, 'fullDetails'])->name('projects.full-details');

Route::get('/projects/{id}/available-organizations', [ProjectOrganizationController::class, 'available'])->name('projects.organizations.available'); 