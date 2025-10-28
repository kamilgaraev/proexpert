<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\ContractorVerificationController;

Route::prefix('contractor-verifications')->middleware(['api'])->group(function () {
    Route::post('{token}/confirm', [ContractorVerificationController::class, 'confirm'])
        ->name('contractor-verifications.confirm');
    
    Route::post('{token}/reject', [ContractorVerificationController::class, 'reject'])
        ->name('contractor-verifications.reject');
    
    Route::post('{token}/dispute', [ContractorVerificationController::class, 'dispute'])
        ->name('contractor-verifications.dispute');
});

