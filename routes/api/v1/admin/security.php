<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\SecuritySessionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api_admin', 'auth.jwt:api_admin', 'auth.session', 'organization.context', 'authorize:admin.access', 'interface:admin'])
    ->prefix('security')
    ->name('security.')
    ->group(function (): void {
        Route::get('sessions', [SecuritySessionController::class, 'index'])->name('sessions.index');
        Route::delete('sessions/{session}', [SecuritySessionController::class, 'destroy'])->name('sessions.destroy');
        Route::post('sessions/revoke-others', [SecuritySessionController::class, 'revokeOthers'])
            ->name('sessions.revoke-others');
        Route::get('events', [SecuritySessionController::class, 'events'])->name('events.index');
    });
