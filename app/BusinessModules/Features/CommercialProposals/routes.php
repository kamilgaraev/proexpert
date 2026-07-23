<?php

declare(strict_types=1);

use App\BusinessModules\Features\CommercialProposals\Http\Controllers\CommercialProposalController;
use App\Support\Routing\AdminRouteStack;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/admin/commercial-proposals')
    ->name('admin.commercial_proposals.')
    ->middleware(AdminRouteStack::middleware(['module.access:commercial-proposals']))
    ->group(function (): void {
        Route::get('/summary', [CommercialProposalController::class, 'summary'])
            ->middleware('authorize:commercial_proposals.view')
            ->name('summary');
        Route::get('/references', [CommercialProposalController::class, 'references'])
            ->middleware('authorize:commercial_proposals.view')
            ->name('references');

        Route::get('/templates', [CommercialProposalController::class, 'templates'])
            ->middleware('authorize:commercial_proposals.templates.manage')
            ->name('templates.index');
        Route::post('/templates', [CommercialProposalController::class, 'storeTemplate'])
            ->middleware('authorize:commercial_proposals.templates.manage')
            ->name('templates.store');

        Route::get('/', [CommercialProposalController::class, 'index'])
            ->middleware('authorize:commercial_proposals.view')
            ->name('index');
        Route::post('/', [CommercialProposalController::class, 'store'])
            ->middleware('authorize:commercial_proposals.create')
            ->name('store');
        Route::get('/{proposalId}', [CommercialProposalController::class, 'show'])
            ->middleware('authorize:commercial_proposals.view')
            ->name('show');
        Route::patch('/{proposalId}', [CommercialProposalController::class, 'updateDraft'])
            ->middleware('authorize:commercial_proposals.update')
            ->name('update');
        Route::delete('/{proposalId}', [CommercialProposalController::class, 'archive'])
            ->middleware('authorize:commercial_proposals.archive')
            ->name('archive');

        Route::post('/{proposalId}/versions', [CommercialProposalController::class, 'createVersion'])
            ->middleware('authorize:commercial_proposals.versions.create')
            ->name('versions.store');
        Route::post('/{proposalId}/approval/request', [CommercialProposalController::class, 'requestApproval'])
            ->middleware('authorize:commercial_proposals.approval.request')
            ->name('approval.request');
        Route::post('/{proposalId}/approval/decision', [CommercialProposalController::class, 'decideApproval'])
            ->middleware('authorize:commercial_proposals.approval.decide')
            ->name('approval.decision');
        Route::post('/{proposalId}/send', [CommercialProposalController::class, 'send'])
            ->middleware('authorize:commercial_proposals.send')
            ->name('send');
        Route::post('/{proposalId}/result', [CommercialProposalController::class, 'recordResult'])
            ->middleware('authorize:commercial_proposals.result')
            ->name('result');
        Route::post('/{proposalId}/contract', [CommercialProposalController::class, 'createContract'])
            ->middleware(['authorize:commercial_proposals.result', 'authorize:contracts.create'])
            ->name('contract.create');

        Route::get('/{proposalId}/preview', [CommercialProposalController::class, 'preview'])
            ->middleware('authorize:commercial_proposals.export')
            ->name('preview');
        Route::post('/{proposalId}/exports', [CommercialProposalController::class, 'export'])
            ->middleware('authorize:commercial_proposals.export')
            ->name('exports.store');
        Route::get('/{proposalId}/exports/{exportId}', [CommercialProposalController::class, 'exportStatus'])
            ->middleware('authorize:commercial_proposals.export')
            ->name('exports.show');

        Route::post('/{proposalId}/files', [CommercialProposalController::class, 'uploadFile'])
            ->middleware('authorize:commercial_proposals.files.upload')
            ->name('files.store');
        Route::delete('/{proposalId}/files/{fileId}', [CommercialProposalController::class, 'deleteFile'])
            ->middleware('authorize:commercial_proposals.files.delete')
            ->name('files.delete');
    });
