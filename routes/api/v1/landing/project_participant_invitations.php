<?php

use App\Http\Controllers\Api\V1\Landing\ProjectParticipantInvitationController;
use Illuminate\Support\Facades\Route;

Route::prefix('project-participant-invitations')->name('projectParticipantInvitations.')->group(function (): void {
    Route::get('/{token}', [ProjectParticipantInvitationController::class, 'show'])
        ->name('show');

    Route::middleware([
        'auth:api_landing',
        'auth.jwt:api_landing',
        'auth.session',
        'verified',
        'organization.context',
        'interface:lk',
        'origin.web:lk',
        'csrf.web:lk',
    ])->post('/{token}/accept', [ProjectParticipantInvitationController::class, 'accept'])
        ->name('accept');
});
