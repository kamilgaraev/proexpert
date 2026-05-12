<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Landing\SecuritySessionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api_landing', 'auth.jwt:api_landing', 'auth.session', 'organization.context'])
    ->prefix('security')
    ->name('security.')
    ->group(function (): void {
        Route::get('sessions', [SecuritySessionController::class, 'index'])->name('sessions.index');
        Route::delete('sessions/{session}', [SecuritySessionController::class, 'destroy'])->name('sessions.destroy');
        Route::post('sessions/revoke-others', [SecuritySessionController::class, 'revokeOthers'])
            ->name('sessions.revoke-others');
        Route::get('events', [SecuritySessionController::class, 'events'])->name('events.index');
    });
