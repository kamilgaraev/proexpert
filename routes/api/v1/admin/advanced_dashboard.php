<?php

use Illuminate\Support\Facades\Route;
use App\BusinessModules\Features\AdvancedDashboard\Http\Controllers\WidgetsController;
use App\BusinessModules\Features\AdvancedDashboard\Http\Controllers\WidgetsRegistryController;

// Advanced Dashboard - Платный модуль
// Требует активации модуля в организации
Route::prefix('advanced-dashboard')->middleware(['feature:advanced_dashboard'])->group(function () {
    
    // Получение данных виджета
    Route::get('/widgets/{type}/data', [WidgetsController::class, 'getData'])->name('advanced-dashboard.widgets.data');
    
    // Пакетная загрузка виджетов
    Route::post('/widgets/batch-data', [WidgetsController::class, 'getBatch'])->name('advanced-dashboard.widgets.batch');
    
    // Метаданные всех виджетов
    Route::get('/widgets/metadata', [WidgetsController::class, 'getMetadata'])->name('advanced-dashboard.widgets.metadata');
    
    // Реестр доступных виджетов
    Route::get('/registry', [WidgetsRegistryController::class, 'getRegistry'])->name('advanced-dashboard.registry');
    
    // Информация о конкретном виджете
    Route::get('/registry/{widgetId}', [WidgetsRegistryController::class, 'getWidgetInfo'])->name('advanced-dashboard.registry.widget');
});

