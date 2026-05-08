<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\ActivityEventController;
use Illuminate\Support\Facades\Route;

Route::prefix('activity')->name('activity.')->group(function (): void {
    Route::get('events/export', [ActivityEventController::class, 'export'])
        ->middleware('authorize:system-logs.activity-events.export')
        ->name('events.export');

    Route::get('events', [ActivityEventController::class, 'index'])
        ->middleware('authorize:system-logs.activity-events.view')
        ->name('events.index');

    Route::get('events/{event}', [ActivityEventController::class, 'show'])
        ->middleware('authorize:system-logs.activity-events.view')
        ->name('events.show');

    Route::get('summary', [ActivityEventController::class, 'summary'])
        ->middleware('authorize:system-logs.activity-events.view')
        ->name('summary');

    Route::get('actors', [ActivityEventController::class, 'actors'])
        ->middleware('authorize:system-logs.activity-events.view')
        ->name('actors');
});
