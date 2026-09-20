<?php

declare(strict_types=1);

use App\BusinessModules\Features\ExecutiveDocumentation\Http\Controllers\ExecutiveDocumentReferenceController;
use App\Support\Routing\AdminRouteStack;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/admin/executive-documentation')
    ->name('admin.executive_documentation.')
    ->middleware(AdminRouteStack::middleware(['executive-documentation.active']))
    ->group(function (): void {
        Route::get('/references/paginated', [ExecutiveDocumentReferenceController::class, 'index'])
            ->middleware('authorize:executive-documentation.view')
            ->name('references.paginated');
    });
