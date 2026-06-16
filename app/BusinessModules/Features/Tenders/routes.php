<?php

declare(strict_types=1);

use App\BusinessModules\Features\Tenders\Http\Controllers\TenderController;
use App\Support\Routing\AdminRouteStack;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/admin/tenders')
    ->name('admin.tenders.')
    ->middleware(AdminRouteStack::middleware(['module.access:tenders']))
    ->group(function (): void {
        Route::get('/summary', [TenderController::class, 'summary'])
            ->middleware('authorize:tenders.view')
            ->name('summary');
        Route::get('/references', [TenderController::class, 'references'])
            ->middleware('authorize:tenders.view')
            ->name('references');
        Route::get('/', [TenderController::class, 'index'])
            ->middleware('authorize:tenders.view')
            ->name('index');
        Route::post('/', [TenderController::class, 'store'])
            ->middleware('authorize:tenders.create')
            ->name('store');
        Route::get('/{tenderId}', [TenderController::class, 'show'])
            ->middleware('authorize:tenders.view')
            ->name('show');
        Route::patch('/{tenderId}', [TenderController::class, 'update'])
            ->middleware('authorize:tenders.update')
            ->name('update');
        Route::delete('/{tenderId}', [TenderController::class, 'archive'])
            ->middleware('authorize:tenders.archive')
            ->name('archive');
        Route::post('/{tenderId}/restore', [TenderController::class, 'restore'])
            ->middleware('authorize:tenders.archive')
            ->name('restore');

        Route::post('/{tenderId}/workflow/analyze', [TenderController::class, 'analyze'])
            ->middleware('authorize:tenders.workflow.analyze')
            ->name('workflow.analyze');
        Route::post('/{tenderId}/workflow/go-no-go', [TenderController::class, 'decideGoNoGo'])
            ->middleware('authorize:tenders.go_no_go.decide')
            ->name('workflow.go_no_go');
        Route::post('/{tenderId}/workflow/submit', [TenderController::class, 'submit'])
            ->middleware('authorize:tenders.workflow.submit')
            ->name('workflow.submit');
        Route::post('/{tenderId}/workflow/result', [TenderController::class, 'recordResult'])
            ->middleware('authorize:tenders.workflow.result')
            ->name('workflow.result');
        Route::post('/{tenderId}/workflow/cancel', [TenderController::class, 'cancel'])
            ->middleware('authorize:tenders.workflow.cancel')
            ->name('workflow.cancel');
    });
