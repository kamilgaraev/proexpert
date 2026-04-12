<?php

use App\Http\Controllers\Api\V1\Brigades\Auth\BrigadeAuthController;
use App\Http\Controllers\Api\V1\Brigades\BrigadeAssignmentController;
use App\Http\Controllers\Api\V1\Brigades\BrigadeDocumentController;
use App\Http\Controllers\Api\V1\Brigades\BrigadeInvitationController;
use App\Http\Controllers\Api\V1\Brigades\BrigadeMemberController;
use App\Http\Controllers\Api\V1\Brigades\BrigadeProfileController;
use App\Http\Controllers\Api\V1\Brigades\BrigadeRequestController;
use App\Http\Controllers\Api\V1\Brigades\BrigadeResponseController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function (): void {
    Route::post('register', [BrigadeAuthController::class, 'register']);
    Route::post('login', [BrigadeAuthController::class, 'login']);
});

Route::middleware(['auth:api_brigade'])->group(function (): void {
    Route::prefix('auth')->group(function (): void {
        Route::get('me', [BrigadeAuthController::class, 'me']);
        Route::post('logout', [BrigadeAuthController::class, 'logout']);
    });

    Route::get('profile', [BrigadeProfileController::class, 'show']);
    Route::put('profile', [BrigadeProfileController::class, 'update']);

    Route::get('members', [BrigadeMemberController::class, 'index']);
    Route::post('members', [BrigadeMemberController::class, 'store']);
    Route::put('members/{memberId}', [BrigadeMemberController::class, 'update']);
    Route::delete('members/{memberId}', [BrigadeMemberController::class, 'destroy']);

    Route::get('documents', [BrigadeDocumentController::class, 'index']);
    Route::post('documents', [BrigadeDocumentController::class, 'store']);
    Route::delete('documents/{documentId}', [BrigadeDocumentController::class, 'destroy']);

    Route::get('requests', [BrigadeRequestController::class, 'index']);
    Route::get('requests/{requestId}', [BrigadeRequestController::class, 'show']);

    Route::get('responses', [BrigadeResponseController::class, 'index']);
    Route::post('responses', [BrigadeResponseController::class, 'store']);

    Route::get('invitations', [BrigadeInvitationController::class, 'index']);
    Route::post('invitations/{invitationId}/accept', [BrigadeInvitationController::class, 'accept']);
    Route::post('invitations/{invitationId}/decline', [BrigadeInvitationController::class, 'decline']);

    Route::get('assignments', [BrigadeAssignmentController::class, 'index']);
});
