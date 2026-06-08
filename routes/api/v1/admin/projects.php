<?php

use App\Http\Controllers\Api\V1\Admin\ProjectChildWorksController;
use App\Http\Controllers\Api\V1\Admin\ProjectController;
use App\Http\Controllers\Api\V1\Admin\ProjectOrganizationController;
use App\Http\Controllers\Api\V1\Admin\ProjectParticipantInvitationController;
use Illuminate\Support\Facades\Route;

// Projects CRUD с детальными правами
Route::get('projects', [ProjectController::class, 'index'])
    ->middleware('authorize:admin.projects.view')
    ->name('projects.index');
Route::post('projects', [ProjectController::class, 'store'])
    ->middleware('authorize:admin.projects.edit')
    ->name('projects.store');

// Получить доступные категории затрат для проектов (должно быть ПЕРЕД projects/{project})
Route::get('/projects/available-cost-categories', [ProjectController::class, 'getAvailableCostCategories'])->name('projects.cost-categories');
Route::get('/projects/address-suggestions', [ProjectController::class, 'addressSuggestions'])
    ->middleware(['authorize:admin.projects.edit', 'throttle:60,1'])
    ->name('projects.address-suggestions');

Route::get('projects/{project}', [ProjectController::class, 'show'])
    ->middleware('authorize:admin.projects.view')
    ->whereNumber('project')
    ->name('projects.show');
Route::put('projects/{project}', [ProjectController::class, 'update'])
    ->middleware('authorize:admin.projects.edit')
    ->whereNumber('project')
    ->name('projects.update');
Route::patch('projects/{project}', [ProjectController::class, 'update'])
    ->middleware('authorize:admin.projects.edit')
    ->whereNumber('project')
    ->name('projects.patch');
Route::delete('projects/{project}', [ProjectController::class, 'destroy'])
    ->middleware('authorize:admin.projects.edit')
    ->whereNumber('project')
    ->name('projects.destroy');

// Дополнительные маршруты для проекта
Route::post('/projects/{projectId}/foremen/{userId}', [ProjectController::class, 'assignForeman'])->name('projects.foremen.assign');
Route::delete('/projects/{projectId}/foremen/{userId}', [ProjectController::class, 'detachForeman'])->name('projects.foremen.detach');

// Получить статистику по проекту
Route::get('/projects/{id}/dashboard', [ProjectController::class, 'dashboard'])
    ->middleware('authorize:admin.projects.analytics')
    ->whereNumber('id')
    ->name('projects.dashboard');

Route::get('/projects/{id}/statistics', [ProjectController::class, 'statistics'])
    ->middleware('authorize:admin.projects.analytics')
    ->whereNumber('id')
    ->name('projects.statistics');

// Получить материалы проекта
Route::get('/projects/{id}/materials', [ProjectController::class, 'getProjectMaterials'])
    ->whereNumber('id')
    ->name('projects.materials');

// Получить типы работ проекта
Route::get('/projects/{id}/work-types', [ProjectController::class, 'getProjectWorkTypes'])
    ->whereNumber('id')
    ->name('projects.workTypes');

// Получить организации проекта
Route::get('/projects/{project}/organizations', [ProjectOrganizationController::class, 'index'])
    ->middleware('project.context')
    ->name('projects.organizations.index');
Route::post('/projects/{project}/organizations', [ProjectOrganizationController::class, 'store'])
    ->middleware(['project.context', 'authorize:projects.organizations.manage'])
    ->name('projects.organizations.store');
Route::get('/projects/{project}/organizations/{organization}', [ProjectOrganizationController::class, 'show'])
    ->middleware('project.context')
    ->name('projects.organizations.show');
Route::patch('/projects/{project}/organizations/{organization}/role', [ProjectOrganizationController::class, 'updateRole'])
    ->middleware(['project.context', 'authorize:projects.organizations.manage'])
    ->name('projects.organizations.role');
Route::delete('/projects/{project}/organizations/{organization}', [ProjectOrganizationController::class, 'destroy'])
    ->middleware(['project.context', 'authorize:projects.organizations.manage'])
    ->name('projects.organizations.destroy');
Route::post('/projects/{project}/organizations/{organization}/activate', [ProjectOrganizationController::class, 'activate'])
    ->middleware(['project.context', 'authorize:projects.organizations.manage'])
    ->name('projects.organizations.activate');
Route::post('/projects/{project}/organizations/{organization}/deactivate', [ProjectOrganizationController::class, 'deactivate'])
    ->middleware(['project.context', 'authorize:projects.organizations.manage'])
    ->name('projects.organizations.deactivate');
Route::get('/projects/{project}/participant-invitations', [ProjectParticipantInvitationController::class, 'index'])
    ->middleware('project.context')
    ->name('projects.participant-invitations.index');
Route::post('/projects/{project}/participant-invitations', [ProjectParticipantInvitationController::class, 'store'])
    ->middleware(['project.context', 'authorize:projects.organizations.manage'])
    ->name('projects.participant-invitations.store');
Route::post('/projects/{project}/participant-invitations/{invitation}/cancel', [ProjectParticipantInvitationController::class, 'cancel'])
    ->middleware(['project.context', 'authorize:projects.organizations.manage'])
    ->name('projects.participant-invitations.cancel');
Route::post('/projects/{project}/participant-invitations/{invitation}/resend', [ProjectParticipantInvitationController::class, 'resend'])
    ->middleware(['project.context', 'authorize:projects.organizations.manage'])
    ->name('projects.participant-invitations.resend');

// Получить детализированные работы дочерних организаций
Route::get('/projects/{id}/child-works', [ProjectChildWorksController::class, 'index'])
    ->middleware('authorize:admin.projects.view')
    ->name('projects.child-works.index');

// Статистика (если понадобится)
// Route::get('projects/{project}/statistics', [ProjectController::class, 'statistics'])->name('projects.statistics');

Route::get('/projects/{id}/full', [ProjectController::class, 'fullDetails'])->name('projects.full-details');

Route::get('/projects/{project}/available-organizations', [ProjectOrganizationController::class, 'available'])
    ->middleware(['project.context', 'authorize:projects.organizations.manage'])
    ->name('projects.organizations.available');
