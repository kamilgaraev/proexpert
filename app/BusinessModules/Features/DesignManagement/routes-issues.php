<?php

declare(strict_types=1);

use App\BusinessModules\Features\DesignManagement\Http\Controllers\DesignProjectIssueController;
use App\Support\Routing\AdminRouteStack;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/admin/design-management')
    ->name('admin.design_management.')
    ->middleware(AdminRouteStack::middleware(['design-management.active']))
    ->group(function (): void {
        Route::get('/projects/{projectId}/issues', [DesignProjectIssueController::class, 'index'])
            ->middleware('authorize:design-management.view')
            ->name('issues.index');
        Route::post('/projects/{projectId}/issues', [DesignProjectIssueController::class, 'store'])
            ->middleware('authorize:design-management.review')
            ->name('issues.store');
        Route::get('/issues/{issueId}', [DesignProjectIssueController::class, 'show'])
            ->middleware('authorize:design-management.view')
            ->name('issues.show');
        Route::post('/issues/{issueId}/assign', [DesignProjectIssueController::class, 'assign'])
            ->middleware('authorize:design-management.review')
            ->name('issues.assign');
        Route::post('/issues/{issueId}/resolve', [DesignProjectIssueController::class, 'resolve'])
            ->middleware('authorize:design-management.review')
            ->name('issues.resolve');
        Route::post('/issues/{issueId}/verify', [DesignProjectIssueController::class, 'verify'])
            ->middleware('authorize:design-management.review')
            ->name('issues.verify');
        Route::post('/issues/{issueId}/blocking-flag', [DesignProjectIssueController::class, 'blocking'])
            ->middleware('authorize:design-management.issues.manage_blocking')
            ->name('issues.blocking');
        Route::post('/issues/{issueId}/snapshot', [DesignProjectIssueController::class, 'snapshot'])
            ->middleware('authorize:design-management.review')
            ->name('issues.snapshot');
        Route::get('/issues/{issueId}/bim-context', [DesignProjectIssueController::class, 'bimContext'])
            ->middleware('authorize:design-management.view')
            ->name('issues.bim_context');
    });
