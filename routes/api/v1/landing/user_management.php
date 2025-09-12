<?php

use Illuminate\Support\Facades\Route;
// use App\Http\Controllers\Api\V1\Landing\OrganizationRoleController; // Заменен на CustomRoleController
use App\Http\Controllers\Api\V1\Landing\UserInvitationController;
use App\Http\Controllers\Api\V1\Landing\OrganizationUserController;

// ВРЕМЕННО ОТКЛЮЧЕНО - заменено на новую систему авторизации
// Используйте маршруты из routes/api/v1/landing/authorization.php
// 
// Route::prefix('roles')->group(function () {
//     Route::get('/', [OrganizationRoleController::class, 'index']);
//     Route::post('/', [OrganizationRoleController::class, 'store']);
//     Route::get('/{roleId}', [OrganizationRoleController::class, 'show']);
//     Route::put('/{roleId}', [OrganizationRoleController::class, 'update']);
//     Route::delete('/{roleId}', [OrganizationRoleController::class, 'destroy']);
//     
//     Route::get('/permissions/available', [OrganizationRoleController::class, 'permissions']);
//     Route::post('/{roleId}/assign-user', [OrganizationRoleController::class, 'assignUser']);
//     Route::delete('/{roleId}/remove-user', [OrganizationRoleController::class, 'removeUser']);
//     Route::post('/{roleId}/duplicate', [OrganizationRoleController::class, 'duplicate']);
// });

Route::prefix('invitations')->group(function () {
    Route::get('/', [UserInvitationController::class, 'index']);
    Route::post('/', [UserInvitationController::class, 'store']);
    Route::get('/{invitationId}', [UserInvitationController::class, 'show']);
    Route::delete('/{invitationId}', [UserInvitationController::class, 'destroy']);
    Route::post('/{invitationId}/resend', [UserInvitationController::class, 'resend']);
    Route::get('/stats/overview', [UserInvitationController::class, 'stats']);
});

Route::prefix('organization-users')->group(function () {
    Route::get('/', [OrganizationUserController::class, 'index']);
    Route::get('/{userId}', [OrganizationUserController::class, 'show']);
    Route::put('/{userId}', [OrganizationUserController::class, 'update']);
    Route::delete('/{userId}', [OrganizationUserController::class, 'destroy']);
    Route::post('/{userId}/toggle-status', [OrganizationUserController::class, 'toggleStatus']);
    Route::get('/{userId}/roles', [OrganizationUserController::class, 'getRoles']);
    Route::post('/{userId}/roles', [OrganizationUserController::class, 'updateRoles']);
});

Route::get('/user-limits', function(\Illuminate\Http\Request $request) {
    $subscriptionLimitsService = app(\App\Services\Billing\SubscriptionLimitsService::class);
    $user = $request->user();
    $limits = $subscriptionLimitsService->getUserLimitsData($user);
    
    return response()->json([
        'success' => true,
        'data' => $limits
    ]);
});

Route::get('/invitation/{token}', [UserInvitationController::class, 'getByToken'])->name('invitation.get');
Route::post('/invitation/{token}/accept', [UserInvitationController::class, 'accept'])->name('invitation.accept'); 