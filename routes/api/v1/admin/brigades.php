<?php

use App\Http\Controllers\Api\V1\Admin\Brigades\BrigadeAssignmentManagementController;
use App\Http\Controllers\Api\V1\Admin\Brigades\BrigadeCatalogController;
use App\Http\Controllers\Api\V1\Admin\Brigades\BrigadeInvitationManagementController;
use App\Http\Controllers\Api\V1\Admin\Brigades\BrigadeRequestManagementController;
use Illuminate\Support\Facades\Route;

Route::prefix('brigades')->group(function (): void {
    Route::get('catalog', [BrigadeCatalogController::class, 'index']);
    Route::get('catalog/{brigadeId}', [BrigadeCatalogController::class, 'show']);
    Route::patch('catalog/{brigadeId}/status', [BrigadeCatalogController::class, 'updateStatus']);
    Route::patch('catalog/{brigadeId}/documents/{documentId}', [BrigadeCatalogController::class, 'updateDocumentStatus']);

    Route::get('requests', [BrigadeRequestManagementController::class, 'index']);
    Route::post('requests', [BrigadeRequestManagementController::class, 'store']);
    Route::post('requests/{requestId}/close', [BrigadeRequestManagementController::class, 'close']);
    Route::post('requests/{requestId}/responses/{responseId}/approve', [BrigadeRequestManagementController::class, 'approveResponse']);

    Route::get('invitations', [BrigadeInvitationManagementController::class, 'index']);
    Route::post('invitations', [BrigadeInvitationManagementController::class, 'store']);
    Route::post('invitations/{invitationId}/cancel', [BrigadeInvitationManagementController::class, 'cancel']);

    Route::get('assignments', [BrigadeAssignmentManagementController::class, 'index']);
    Route::patch('assignments/{assignmentId}', [BrigadeAssignmentManagementController::class, 'update']);
});
