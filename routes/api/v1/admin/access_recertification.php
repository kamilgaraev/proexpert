<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\AccessRecertificationController;
use Illuminate\Support\Facades\Route;

Route::prefix('access-recertification')->group(function (): void {
    Route::get('/campaigns', [AccessRecertificationController::class, 'index'])
        ->middleware('authorize:access_recertification.campaigns.view');
    Route::post('/campaigns', [AccessRecertificationController::class, 'store'])
        ->middleware('authorize:access_recertification.campaigns.manage');
    Route::get('/campaigns/{campaign}', [AccessRecertificationController::class, 'show'])
        ->middleware('authorize:access_recertification.campaigns.view');
    Route::put('/campaigns/{campaign}', [AccessRecertificationController::class, 'update'])
        ->middleware('authorize:access_recertification.campaigns.manage');
    Route::post('/campaigns/{campaign}/launch', [AccessRecertificationController::class, 'launch'])
        ->middleware('authorize:access_recertification.campaigns.launch');
    Route::post('/campaigns/{campaign}/complete', [AccessRecertificationController::class, 'complete'])
        ->middleware('authorize:access_recertification.campaigns.complete');
    Route::post('/campaigns/{campaign}/cancel', [AccessRecertificationController::class, 'cancel'])
        ->middleware('authorize:access_recertification.campaigns.manage');
    Route::get('/campaigns/{campaign}/items', [AccessRecertificationController::class, 'items'])
        ->middleware('authorize:access_recertification.reviews.view');

    Route::get('/reviews/my', [AccessRecertificationController::class, 'reviewQueue'])
        ->middleware('authorize:access_recertification.reviews.view');
    Route::post('/items/{item}/decisions', [AccessRecertificationController::class, 'decide'])
        ->middleware('authorize:access_recertification.reviews.decide');
    Route::post('/items/{item}/reassign', [AccessRecertificationController::class, 'reassign'])
        ->middleware('authorize:access_recertification.campaigns.manage');

    Route::get('/revocations', [AccessRecertificationController::class, 'revocations'])
        ->middleware('authorize:access_recertification.revocations.execute');
    Route::post('/revocations/{revocation}/complete', [AccessRecertificationController::class, 'completeRevocation'])
        ->middleware('authorize:access_recertification.revocations.execute');

    Route::get('/exceptions', [AccessRecertificationController::class, 'exceptions'])
        ->middleware('authorize:access_recertification.exceptions.approve');
    Route::post('/exceptions/{exception}/decision', [AccessRecertificationController::class, 'decideException'])
        ->middleware('authorize:access_recertification.exceptions.approve');

    Route::get('/reports/summary', [AccessRecertificationController::class, 'report'])
        ->middleware('authorize:access_recertification.reports.view');
    Route::get('/reports/evidence-export', [AccessRecertificationController::class, 'export'])
        ->middleware('authorize:access_recertification.reports.export');
});
