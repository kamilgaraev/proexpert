<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\LogViewController;

/*
|--------------------------------------------------------------------------
| Admin API V1 Log Viewing Routes
|--------------------------------------------------------------------------
|
| Маршруты для просмотра логов операций прорабов.
|
*/

// Группа уже защищена middleware стеком авторизации админки
// в RouteServiceProvider. Дополнительно контроллер проверяет 'can:view-operation-logs'.

Route::prefix('logs')->name('logs.')->group(function () {
    Route::get('material-usage', [LogViewController::class, 'getMaterialLogs'])->name('material_usage.index');
    Route::get('work-completion', [LogViewController::class, 'getWorkLogs'])->name('work_completion.index');
}); 