<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation;

use App\Http\Controllers\Api\V1\Admin\Pto\PtoWorkspaceController;
use App\Support\Routing\AdminRouteStack;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class ExecutiveDocumentationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ExecutiveDocumentationModule::class);
        $this->app->singleton(Services\ExecutiveDocumentNumberGenerator::class);
        $this->app->singleton(Services\ExecutiveDocumentationWorkflowService::class);
        $this->app->singleton(Services\ExecutiveDocumentRequirementsService::class);
        $this->app->singleton(Services\ExecutiveDocumentRequirementsQueryService::class);
        $this->app->singleton(Services\ExecutiveDocumentationService::class);
        $this->app->singleton(Services\ExecutiveDocumentReferenceService::class);
        $this->app->singleton(\App\Services\Pto\PtoWorkspaceQuery::class);
        $this->app->singleton(\App\Services\Pto\PtoWorkspaceTaskSync::class);
    }

    public function boot(): void
    {
        $migrationsPath = __DIR__ . '/migrations';
        if (is_dir($migrationsPath)) {
            $this->loadMigrationsFrom($migrationsPath);
        }

        $routesPath = __DIR__ . '/routes.php';
        if (is_file($routesPath)) {
            require $routesPath;
        }

        $referenceRoutesPath = __DIR__ . '/routes-reference.php';
        if (is_file($referenceRoutesPath)) {
            require $referenceRoutesPath;
        }

        Route::middleware(AdminRouteStack::middleware(['executive-documentation.active']))
            ->prefix('api/v1/admin/executive-documentation')
            ->group(function (): void {
                Route::get('/projects/{projectId}/approved-lists', [Http\Controllers\ExecutiveDocumentApprovedListController::class, 'index'])->middleware('authorize:executive-documentation.view');
                Route::post('/projects/{projectId}/approved-lists', [Http\Controllers\ExecutiveDocumentApprovedListController::class, 'store'])->middleware('authorize:executive-documentation.approve');
                Route::get('/projects/{projectId}/approved-lists/{listId}/download', [Http\Controllers\ExecutiveDocumentApprovedListController::class, 'download'])->middleware('authorize:executive-documentation.view');
                Route::post('/sets/{set}/approved-list', [Http\Controllers\ExecutiveDocumentApprovedListController::class, 'apply'])->middleware('authorize:executive-documentation.edit');
                Route::get('/sets/{set}/requirements', [Http\Controllers\ExecutiveDocumentRequirementsController::class, 'index'])->middleware('authorize:executive-documentation.view');
                Route::get('/sets/{set}/requirements/history', [Http\Controllers\ExecutiveDocumentRequirementsController::class, 'history'])->middleware('authorize:executive-documentation.view');
                Route::put('/sets/{set}/requirements', [Http\Controllers\ExecutiveDocumentRequirementsController::class, 'replace'])->middleware('authorize:executive-documentation.edit');
                Route::post('/requirements/{requirement}/not-applicable', [Http\Controllers\ExecutiveDocumentRequirementsController::class, 'markNotApplicable'])->middleware('authorize:executive-documentation.approve');
                Route::post('/requirements/{requirement}/applicable', [Http\Controllers\ExecutiveDocumentRequirementsController::class, 'markApplicable'])->middleware('authorize:executive-documentation.approve');
                Route::post('/requirements/{requirement}/conditions', [Http\Controllers\ExecutiveDocumentRequirementsController::class, 'conditions'])->middleware('authorize:executive-documentation.approve');
                Route::post('/requirements/{requirement}/evidence', [Http\Controllers\ExecutiveDocumentRequirementsController::class, 'attachEvidence'])->middleware('authorize:executive-documentation.edit');
            });

        Route::middleware(AdminRouteStack::middleware(['executive-documentation.active']))
            ->prefix('api/v1/admin/pto')
            ->group(function (): void {
                Route::get('/work-queue', [PtoWorkspaceController::class, 'queue'])->middleware('authorize:executive-documentation.view');
                Route::get('/completeness', [PtoWorkspaceController::class, 'completeness'])->middleware('authorize:executive-documentation.view');
                Route::post('/tasks', [PtoWorkspaceController::class, 'upsertTask'])->middleware('authorize:executive-documentation.edit');
                Route::post('/tasks/{task}/complete', [PtoWorkspaceController::class, 'completeTask'])->middleware('authorize:executive-documentation.edit');
            });

        $this->app['router']->aliasMiddleware(
            'executive-documentation.active',
            Http\Middleware\EnsureExecutiveDocumentationActive::class
        );
    }
}
