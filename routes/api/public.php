<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Public\ContactFormController;

Route::get('legal', \App\Http\Controllers\Api\Public\LegalController::class);
Route::post('legal/analytics-consent', \App\Http\Controllers\Api\Public\AnalyticsConsentController::class)->middleware('throttle:30,1');
Route::post('legal/analytics-status', \App\Http\Controllers\Api\Public\AnalyticsConsentStatusController::class)->middleware('throttle:60,1');

Route::prefix('contact')->group(function () {
    Route::post('/', [ContactFormController::class, 'store'])->name('contact.store');
});
