<?php

declare(strict_types=1);

use App\BusinessModules\Features\Procurement\Http\Controllers\Mobile\ProcurementController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/mobile/procurement')
    ->name('mobile.procurement.')
    ->middleware(['auth:api_mobile', 'auth.jwt:api_mobile', 'organization.context', 'can:access-mobile-app', 'procurement.active'])
    ->group(function (): void {
        Route::get('/summary', [ProcurementController::class, 'summary'])->name('summary');
        Route::get('/purchase-requests', [ProcurementController::class, 'purchaseRequests'])->name('purchase_requests.index');
        Route::get('/purchase-requests/{purchaseRequest}', [ProcurementController::class, 'purchaseRequest'])->name('purchase_requests.show');
        Route::get('/purchase-orders', [ProcurementController::class, 'purchaseOrders'])->name('purchase_orders.index');
        Route::get('/purchase-orders/{purchaseOrder}', [ProcurementController::class, 'purchaseOrder'])->name('purchase_orders.show');
        Route::post('/purchase-orders/{purchaseOrder}/receive-materials', [ProcurementController::class, 'receiveMaterials'])->name('purchase_orders.receive_materials');
        Route::post('/purchase-orders/{purchaseOrder}/comments', [ProcurementController::class, 'commentOrder'])->name('purchase_orders.comments.store');
        Route::get('/approvals', [ProcurementController::class, 'approvals'])->name('approvals.index');
        Route::post('/approvals/{approval}/approve', [ProcurementController::class, 'approve'])->name('approvals.approve');
        Route::post('/approvals/{approval}/reject', [ProcurementController::class, 'reject'])->name('approvals.reject');
    });
