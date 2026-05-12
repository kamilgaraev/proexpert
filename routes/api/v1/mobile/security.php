<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Mobile\SecuritySessionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api_mobile', 'auth.jwt:api_mobile', 'auth.session', 'organization.context', 'can:access-mobile-app'])
    ->prefix('security')
    ->name('security.')
    ->group(function (): void {
        Route::get('sessions', [SecuritySessionController::class, 'index'])->name('sessions.index');
        Route::delete('sessions/{session}', [SecuritySessionController::class, 'destroy'])->name('sessions.destroy');
        Route::post('sessions/revoke-others', [SecuritySessionController::class, 'revokeOthers'])
            ->name('sessions.revoke-others');
    });
