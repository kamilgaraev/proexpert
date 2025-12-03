<?php

use Illuminate\Support\Facades\Route;
use App\BusinessModules\Features\Procurement\Http\Controllers\PurchaseRequestController;
use App\BusinessModules\Features\Procurement\Http\Controllers\PurchaseOrderController;
use App\BusinessModules\Features\Procurement\Http\Controllers\SupplierProposalController;
use App\BusinessModules\Features\Procurement\Http\Controllers\PurchaseContractController;
use App\BusinessModules\Features\Procurement\Http\Controllers\ProcurementDashboardController;

/*
|--------------------------------------------------------------------------
| Procurement Module Routes
|--------------------------------------------------------------------------
|
| Маршруты для модуля "Управление закупками"
| Все маршруты защищены middleware: auth:api_admin, organization.context, procurement.active
|
*/

Route::prefix('api/v1/admin/procurement')
    ->name('admin.procurement.')
    ->middleware(['auth:api_admin', 'auth.jwt:api_admin', 'organization.context', 'procurement.active'])
    ->group(function () {
        
        // ============================================
        // Заявки на закупку
        // ============================================
        Route::prefix('purchase-requests')->name('purchase_requests.')->group(function () {
            Route::get('/', [PurchaseRequestController::class, 'index'])->name('index');
            Route::post('/', [PurchaseRequestController::class, 'store'])->name('store');
            Route::get('/{id}', [PurchaseRequestController::class, 'show'])->name('show');
            Route::post('/{id}/approve', [PurchaseRequestController::class, 'approve'])->name('approve');
            Route::post('/{id}/reject', [PurchaseRequestController::class, 'reject'])->name('reject');
            Route::post('/{id}/create-order', [PurchaseRequestController::class, 'createOrder'])->name('create_order');
        });
        
        // ============================================
        // Заказы поставщикам
        // ============================================
        Route::prefix('purchase-orders')->name('purchase_orders.')->group(function () {
            Route::get('/', [PurchaseOrderController::class, 'index'])->name('index');
            Route::post('/', [PurchaseOrderController::class, 'store'])->name('store');
            Route::get('/{id}', [PurchaseOrderController::class, 'show'])->name('show');
            Route::post('/{id}/send', [PurchaseOrderController::class, 'send'])->name('send');
            Route::post('/{id}/confirm', [PurchaseOrderController::class, 'confirm'])->name('confirm');
            Route::post('/{id}/create-contract', [PurchaseOrderController::class, 'createContract'])->name('create_contract');
        });
        
        // ============================================
        // Коммерческие предложения
        // ============================================
        Route::prefix('proposals')->name('proposals.')->group(function () {
            Route::get('/', [SupplierProposalController::class, 'index'])->name('index');
            Route::post('/', [SupplierProposalController::class, 'store'])->name('store');
            Route::get('/{id}', [SupplierProposalController::class, 'show'])->name('show');
            Route::post('/{id}/accept', [SupplierProposalController::class, 'accept'])->name('accept');
            Route::post('/{id}/reject', [SupplierProposalController::class, 'reject'])->name('reject');
        });
        
        // ============================================
        // Договоры поставки
        // ============================================
        Route::prefix('contracts')->name('contracts.')->group(function () {
            Route::get('/', [PurchaseContractController::class, 'index'])->name('index');
            Route::post('/', [PurchaseContractController::class, 'store'])->name('store');
            Route::get('/{id}', [PurchaseContractController::class, 'show'])->name('show');
        });
        
        // ============================================
        // Дашборд
        // ============================================
        Route::prefix('dashboard')->name('dashboard.')->group(function () {
            Route::get('/', [ProcurementDashboardController::class, 'index'])->name('index');
            Route::get('/statistics', [ProcurementDashboardController::class, 'statistics'])->name('statistics');
        });
    });

