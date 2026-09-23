<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Mobile\MobileMyActionsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api_mobile', 'auth.jwt:api_mobile', 'organization.context', 'can:access-mobile-app'])
    ->get('/my-actions', [MobileMyActionsController::class, 'index'])
    ->name('my-actions.index');
