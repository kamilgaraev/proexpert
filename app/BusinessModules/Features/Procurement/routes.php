<?php

use App\BusinessModules\Features\Procurement\Http\Controllers\ProcurementDashboardController;
use App\BusinessModules\Features\Procurement\Http\Controllers\ProcurementSettingsController;
use App\BusinessModules\Features\Procurement\Http\Controllers\PurchaseContractController;
use App\BusinessModules\Features\Procurement\Http\Controllers\PurchaseOrderController;
use App\BusinessModules\Features\Procurement\Http\Controllers\PurchaseRequestController;
use App\BusinessModules\Features\Procurement\Http\Controllers\SupplierProposalController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/admin/procurement')
    ->name('admin.procurement.')
    ->middleware(['auth:api_admin', 'auth.jwt:api_admin', 'organization.context', 'procurement.active'])
    ->group(function () {
        Route::prefix('purchase-requests')->name('purchase_requests.')->group(function () {
            Route::get('/', [PurchaseRequestController::class, 'index'])
                ->middleware('authorize:procurement.purchase_requests.view')
                ->name('index');
            Route::post('/', [PurchaseRequestController::class, 'store'])
                ->middleware('authorize:procurement.purchase_requests.create')
                ->name('store');
            Route::get('/{id}', [PurchaseRequestController::class, 'show'])
                ->middleware('authorize:procurement.purchase_requests.view')
                ->name('show');
            Route::post('/{id}/approve', [PurchaseRequestController::class, 'approve'])
                ->middleware('authorize:procurement.purchase_requests.approve')
                ->name('approve');
            Route::post('/{id}/reject', [PurchaseRequestController::class, 'reject'])
                ->middleware('authorize:procurement.purchase_requests.reject')
                ->name('reject');
            Route::post('/{id}/create-order', [PurchaseRequestController::class, 'createOrder'])
                ->middleware('authorize:procurement.purchase_orders.create')
                ->name('create_order');
        });

        Route::prefix('purchase-orders')->name('purchase_orders.')->group(function () {
            Route::get('/', [PurchaseOrderController::class, 'index'])
                ->middleware('authorize:procurement.purchase_orders.view')
                ->name('index');
            Route::post('/', [PurchaseOrderController::class, 'store'])
                ->middleware('authorize:procurement.purchase_orders.create')
                ->name('store');
            Route::get('/{id}', [PurchaseOrderController::class, 'show'])
                ->middleware('authorize:procurement.purchase_orders.view')
                ->name('show');
            Route::get('/{id}/items', [PurchaseOrderController::class, 'items'])
                ->middleware('authorize:procurement.purchase_orders.view')
                ->name('items');
            Route::post('/{id}/send', [PurchaseOrderController::class, 'send'])
                ->middleware('authorize:procurement.purchase_orders.send')
                ->name('send');
            Route::post('/{id}/confirm', [PurchaseOrderController::class, 'confirm'])
                ->middleware('authorize:procurement.purchase_orders.confirm')
                ->name('confirm');
            Route::post('/{id}/mark-in-delivery', [PurchaseOrderController::class, 'markInDelivery'])
                ->middleware('authorize:procurement.purchase_orders.mark_delivery')
                ->name('mark_in_delivery');
            Route::post('/{id}/receive-materials', [PurchaseOrderController::class, 'receiveMaterials'])
                ->middleware('authorize:procurement.purchase_orders.receive')
                ->name('receive_materials');
            Route::post('/{id}/create-contract', [PurchaseOrderController::class, 'createContract'])
                ->middleware('authorize:procurement.contracts.create')
                ->name('create_contract');
        });

        Route::prefix('proposals')->name('proposals.')->group(function () {
            Route::get('/', [SupplierProposalController::class, 'index'])
                ->middleware('authorize:procurement.supplier_proposals.view')
                ->name('index');
            Route::post('/', [SupplierProposalController::class, 'store'])
                ->middleware('authorize:procurement.supplier_proposals.create')
                ->name('store');
            Route::get('/{id}', [SupplierProposalController::class, 'show'])
                ->middleware('authorize:procurement.supplier_proposals.view')
                ->name('show');
            Route::post('/{id}/accept', [SupplierProposalController::class, 'accept'])
                ->middleware('authorize:procurement.supplier_proposals.accept')
                ->name('accept');
            Route::post('/{id}/reject', [SupplierProposalController::class, 'reject'])
                ->middleware('authorize:procurement.supplier_proposals.reject')
                ->name('reject');
        });

        Route::prefix('contracts')->name('contracts.')->group(function () {
            Route::get('/', [PurchaseContractController::class, 'index'])
                ->middleware('authorize:procurement.contracts.view')
                ->name('index');
            Route::post('/', [PurchaseContractController::class, 'store'])
                ->middleware('authorize:procurement.contracts.create')
                ->name('store');
            Route::get('/{id}', [PurchaseContractController::class, 'show'])
                ->middleware('authorize:procurement.contracts.view')
                ->name('show');
        });

        Route::prefix('dashboard')->name('dashboard.')->group(function () {
            Route::get('/', [ProcurementDashboardController::class, 'index'])
                ->middleware('authorize:procurement.dashboard.view')
                ->name('index');
            Route::get('/statistics', [ProcurementDashboardController::class, 'statistics'])
                ->middleware('authorize:procurement.statistics.view')
                ->name('statistics');
        });

        Route::get('/audit-logs', [PurchaseOrderController::class, 'auditLogs'])
            ->middleware('authorize:procurement.view')
            ->name('audit_logs.index');

        Route::prefix('settings')->name('settings.')->group(function () {
            Route::get('/', [ProcurementSettingsController::class, 'show'])
                ->middleware('authorize:procurement.settings.view')
                ->name('show');
            Route::put('/', [ProcurementSettingsController::class, 'update'])
                ->middleware('authorize:procurement.settings.manage')
                ->name('update');
            Route::post('/reset', [ProcurementSettingsController::class, 'reset'])
                ->middleware('authorize:procurement.settings.manage')
                ->name('reset');
        });
    });
