<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\SiteRequestController;

/*
|--------------------------------------------------------------------------
| Admin API V1 Site Requests Routes
|--------------------------------------------------------------------------
|
| Маршруты для управления заявками (включая заявки на персонал) в административной панели.
|
*/

// Группа уже защищена middleware в RouteServiceProvider

// Site Requests Management
Route::apiResource('site-requests', SiteRequestController::class);

// Дополнительные маршруты для заявок
Route::get('site-requests-stats', [SiteRequestController::class, 'getStats'])->name('site-requests.stats');
Route::post('site-requests/{siteRequest}/files', [SiteRequestController::class, 'uploadFile'])->name('site-requests.upload-file');
Route::delete('site-requests/{siteRequest}/files/{file}', [SiteRequestController::class, 'deleteFile'])->name('site-requests.delete-file');