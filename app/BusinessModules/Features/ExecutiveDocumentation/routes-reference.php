<?php

declare(strict_types=1);

use App\BusinessModules\Features\ExecutiveDocumentation\Http\Controllers\ExecutiveDocumentReferenceController;
use App\BusinessModules\Features\ExecutiveDocumentation\Http\Controllers\ExecutiveDocumentImportController;
use App\Support\Routing\AdminRouteStack;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/admin/executive-documentation')
    ->name('admin.executive_documentation.')
    ->middleware(AdminRouteStack::middleware(['executive-documentation.active']))
    ->group(function (): void {
        Route::get('/references/paginated', [ExecutiveDocumentReferenceController::class, 'index'])
            ->middleware('authorize:executive-documentation.view')
            ->name('references.paginated');
        Route::middleware('authorize:executive-documentation.create')->group(function (): void {
            Route::get('/sets/{set}/imports', [ExecutiveDocumentImportController::class, 'index']);
            Route::post('/sets/{set}/imports', [ExecutiveDocumentImportController::class, 'store']);
            Route::get('/imports/{batch}', [ExecutiveDocumentImportController::class, 'show']);
            Route::post('/imports/{batch}/items/{importItem}/file', [ExecutiveDocumentImportController::class, 'upload']);
            Route::put('/imports/{batch}/mapping', [ExecutiveDocumentImportController::class, 'map']);
            Route::post('/imports/{batch}/start', [ExecutiveDocumentImportController::class, 'start']);
        });
    });
