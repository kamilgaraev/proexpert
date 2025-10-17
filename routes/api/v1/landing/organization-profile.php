<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Landing\OrganizationProfileController;
use App\Http\Controllers\Api\V1\Landing\MyProjectsController;

/**
 * ORGANIZATION PROFILE & CAPABILITIES MANAGEMENT
 * 
 * Routes для управления профилем организации в ЛК
 * Require: auth:api_landing, organization.context
 */

Route::middleware(['auth:api_landing', 'auth.jwt:api_landing', 'organization.context'])->group(function () {
    
    // === ORGANIZATION PROFILE ===
    Route::prefix('organization')->group(function () {
        // Profile Management
        Route::get('/profile', [OrganizationProfileController::class, 'getProfile']);
        Route::put('/profile/capabilities', [OrganizationProfileController::class, 'updateCapabilities']);
        Route::put('/profile/business-type', [OrganizationProfileController::class, 'updateBusinessType']);
        Route::put('/profile/specializations', [OrganizationProfileController::class, 'updateSpecializations']);
        Route::put('/profile/certifications', [OrganizationProfileController::class, 'updateCertifications']);
        
        // Onboarding
        Route::post('/profile/complete-onboarding', [OrganizationProfileController::class, 'completeOnboarding']);
        
        // Capabilities Dictionary
        Route::get('/capabilities', [OrganizationProfileController::class, 'getAvailableCapabilities']);
    });
    
    // === MY PROJECTS (Обзорный список для ЛК) ===
    Route::prefix('my-projects')->group(function () {
        Route::get('/', [MyProjectsController::class, 'index']);
        Route::get('/{project}', [MyProjectsController::class, 'show']);
    });
});

