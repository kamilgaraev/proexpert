<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Landing\ContractorInvitationController;

Route::prefix('contractor-invitations')->group(function () {
    Route::get('/', [ContractorInvitationController::class, 'index'])->name('landing.contractor-invitations.index');
    Route::get('/stats', [ContractorInvitationController::class, 'stats'])->name('landing.contractor-invitations.stats');
    Route::get('/{token}', [ContractorInvitationController::class, 'show'])->name('landing.contractor-invitations.show');
    Route::post('/{token}/accept', [ContractorInvitationController::class, 'accept'])->name('landing.contractor-invitations.accept');
    Route::post('/{token}/decline', [ContractorInvitationController::class, 'decline'])->name('landing.contractor-invitations.decline');
});