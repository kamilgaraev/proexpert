<?php

use Illuminate\Support\Facades\Route;
use App\BusinessModules\Features\Notifications\Http\Controllers\NotificationController;
use App\BusinessModules\Features\Notifications\Http\Controllers\NotificationPreferencesController;
use App\BusinessModules\Features\Notifications\Http\Controllers\NotificationTrackingController;
use App\BusinessModules\Features\Notifications\Http\Controllers\NotificationAnalyticsController;

Route::middleware(['api', 'auth:api'])->prefix('api')->group(function () {
    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index'])->name('notifications.index');
        Route::get('/unread-count', [NotificationController::class, 'getUnreadCount'])->name('notifications.unread-count');
        Route::post('/mark-all-read', [NotificationController::class, 'markAllAsRead'])->name('notifications.mark-all-read');
        Route::get('/{id}', [NotificationController::class, 'show'])->name('notifications.show');
        Route::post('/{id}/mark-read', [NotificationController::class, 'markAsRead'])->name('notifications.mark-read');
        Route::post('/{id}/mark-unread', [NotificationController::class, 'markAsUnread'])->name('notifications.mark-unread');
        Route::delete('/{id}', [NotificationController::class, 'destroy'])->name('notifications.destroy');

        Route::prefix('preferences')->group(function () {
            Route::get('/', [NotificationPreferencesController::class, 'index'])->name('notifications.preferences.index');
            Route::post('/', [NotificationPreferencesController::class, 'update'])->name('notifications.preferences.update');
            Route::post('/quiet-hours', [NotificationPreferencesController::class, 'updateQuietHours'])->name('notifications.preferences.quiet-hours');
        });

        Route::prefix('analytics')->middleware('authorize:notifications.view_analytics')->group(function () {
            Route::get('/stats', [NotificationAnalyticsController::class, 'getStats'])->name('notifications.analytics.stats');
            Route::get('/stats-by-channel', [NotificationAnalyticsController::class, 'getStatsByChannel'])->name('notifications.analytics.stats-by-channel');
        });
    });
});

Route::prefix('track')->middleware('web')->group(function () {
    Route::get('/open/{tracking_id}', [NotificationTrackingController::class, 'trackOpen'])->name('notifications.track.open');
    Route::get('/click/{tracking_id}', [NotificationTrackingController::class, 'trackClick'])->name('notifications.track.click');
});

