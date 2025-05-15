<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Mobile\SiteRequestController;

// Маршруты для заявок с объекта (для мобильного приложения прораба)
// Предполагается, что группа маршрутов 'mobile' уже защищена необходимыми middleware (auth:api_mobile, organization.context и т.д.)

Route::apiResource('site-requests', SiteRequestController::class);

// Дополнительный маршрут для удаления вложения (фотографии)
Route::delete('site-requests/{site_request}/attachments/{file}', [SiteRequestController::class, 'deleteAttachment'])
    ->name('site-requests.attachments.destroy');
    // ->whereNumber('file'); // Если ID файла всегда числовой, можно добавить ограничение 