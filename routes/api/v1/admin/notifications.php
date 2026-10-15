<?php

use Illuminate\Support\Facades\Route;
use App\BusinessModules\Features\Notifications\Http\Controllers\NotificationController;
use App\BusinessModules\Features\Notifications\Http\Controllers\AdminNotificationPreferencesController;

/*
|--------------------------------------------------------------------------
| Notifications Routes
|--------------------------------------------------------------------------
|
| Используем dashboard rate limiter для уведомлений, т.к. они часто
| вызываются вместе с загрузкой дашборда (polling для новых уведомлений)
|
*/

Route::middleware('throttle:dashboard')->group(function () {
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/unread-count', [NotificationController::class, 'getUnreadCount'])->name('notifications.unread-count');
    Route::get('/notifications/unread', [NotificationController::class, 'unread'])->name('notifications.unread');
    Route::get('/notifications/preferences', [AdminNotificationPreferencesController::class, 'index'])->name('notifications.preferences.index');
    Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllAsRead'])->name('notifications.mark-all-read');
    Route::get('/notifications/{id}', [NotificationController::class, 'show'])->name('notifications.show');
    Route::patch('/notifications/{id}/read', [NotificationController::class, 'markAsRead'])->name('notifications.mark-as-read');
    Route::patch('/notifications/{id}/unread', [NotificationController::class, 'markAsUnread'])->name('notifications.mark-as-unread');
    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy'])->name('notifications.destroy');
});

