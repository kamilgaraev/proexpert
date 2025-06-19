<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\MaterialAnalyticsController;

Route::prefix('materials/analytics')->name('materials.analytics.')->group(function () {
    Route::get('summary', [MaterialAnalyticsController::class, 'summary'])->name('summary');
    Route::get('usage-by-projects', [MaterialAnalyticsController::class, 'usageByProjects'])->name('usage_by_projects');
    Route::get('usage-by-suppliers', [MaterialAnalyticsController::class, 'usageBySuppliers'])->name('usage_by_suppliers');
    Route::get('low-stock', [MaterialAnalyticsController::class, 'lowStock'])->name('low_stock');
    Route::get('most-used', [MaterialAnalyticsController::class, 'mostUsed'])->name('most_used');
    Route::get('cost-history', [MaterialAnalyticsController::class, 'costHistory'])->name('cost_history');
    Route::get('movement-report', [MaterialAnalyticsController::class, 'movementReport'])->name('movement_report');
    Route::get('inventory-report', [MaterialAnalyticsController::class, 'inventoryReport'])->name('inventory_report');
    Route::get('cost-dynamics-report', [MaterialAnalyticsController::class, 'costDynamicsReport'])->name('cost_dynamics_report');
}); 