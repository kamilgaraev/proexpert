<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\ImmutableAuditController;
use Illuminate\Support\Facades\Route;

Route::prefix('immutable-audit')->name('immutable-audit.')->group(function (): void {
    Route::get('events/export', [ImmutableAuditController::class, 'export'])
        ->middleware('authorize:immutable_audit.events.export')
        ->name('events.export');

    Route::get('events', [ImmutableAuditController::class, 'index'])
        ->middleware('authorize:immutable_audit.events.view')
        ->name('events.index');

    Route::get('events/{event}', [ImmutableAuditController::class, 'show'])
        ->middleware('authorize:immutable_audit.events.view')
        ->name('events.show');

    Route::get('events/{event}/integrity', [ImmutableAuditController::class, 'eventIntegrity'])
        ->middleware('authorize:immutable_audit.integrity.verify')
        ->name('events.integrity');

    Route::get('integrity', [ImmutableAuditController::class, 'integrity'])
        ->middleware('authorize:immutable_audit.integrity.verify')
        ->name('integrity.index');
});
