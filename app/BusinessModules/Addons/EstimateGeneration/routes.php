<?php

declare(strict_types=1);

use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationController;
use Illuminate\Support\Facades\Route;

Route::middleware([
    'api',
    'auth:api_admin',
    'auth.jwt:api_admin',
    'organization.context',
    'authorize:admin.access',
    'interface:admin',
    'project.context',
])
    ->prefix('api/v1/admin/projects/{project}/estimate-generation/sessions')
    ->name('api.v1.admin.projects.estimate-generation.sessions.')
    ->group(function (): void {
        Route::get('/', [EstimateGenerationController::class, 'index'])->name('index');
        Route::post('/', [EstimateGenerationController::class, 'store'])->name('store');
        Route::post('/{session}/documents', [EstimateGenerationController::class, 'uploadDocuments'])->name('documents.store');
        Route::post('/{session}/analyze', [EstimateGenerationController::class, 'analyze'])->name('analyze');
        Route::post('/{session}/generate', [EstimateGenerationController::class, 'generate'])->name('generate');
        Route::get('/{session}', [EstimateGenerationController::class, 'show'])->name('show');
        Route::get('/{session}/draft', [EstimateGenerationController::class, 'draft'])->name('draft');
        Route::get('/{session}/export', [EstimateGenerationController::class, 'export'])->name('export');
        Route::post('/{session}/apply', [EstimateGenerationController::class, 'apply'])->name('apply');
        Route::post('/{session}/rebuild-section', [EstimateGenerationController::class, 'rebuildSection'])->name('rebuild-section');
        Route::post('/{session}/feedback', [EstimateGenerationController::class, 'feedback'])->name('feedback');
    });
