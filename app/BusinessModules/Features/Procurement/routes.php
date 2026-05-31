<?php

use App\BusinessModules\Features\Procurement\Http\Controllers\ProcurementApprovalController;
use App\BusinessModules\Features\Procurement\Http\Controllers\ProcurementAuditEventController;
use App\BusinessModules\Features\Procurement\Http\Controllers\ProcurementDashboardController;
use App\BusinessModules\Features\Procurement\Http\Controllers\ProcurementIssueController;
use App\BusinessModules\Features\Procurement\Http\Controllers\ProcurementSettingsController;
use App\BusinessModules\Features\Procurement\Http\Controllers\PublicSupplierRequestController;
use App\BusinessModules\Features\Procurement\Http\Controllers\PurchaseContractController;
use App\BusinessModules\Features\Procurement\Http\Controllers\PurchaseOrderController;
use App\BusinessModules\Features\Procurement\Http\Controllers\PurchaseRequestController;
use App\BusinessModules\Features\Procurement\Http\Controllers\SupplierProposalController;
use App\BusinessModules\Features\Procurement\Http\Controllers\SupplierProposalDecisionController;
use App\BusinessModules\Features\Procurement\Http\Controllers\SupplierRequestController;
use App\Http\Responses\AdminResponse;
use App\Support\Routing\AdminRouteStack;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/procurement/supplier-requests')
    ->name('public.procurement.supplier_requests.')
    ->middleware(['api', 'throttle:60,1'])
    ->group(function () {
        Route::get('/{token}', [PublicSupplierRequestController::class, 'show'])->name('show');
        Route::post('/{token}/proposals', [PublicSupplierRequestController::class, 'submit'])->name('submit');
    });

Route::prefix('api/v1/admin/procurement')
    ->name('admin.procurement.')
    ->middleware(AdminRouteStack::middleware(['procurement.active']))
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
            Route::get('/{id}/proposal-comparison', [SupplierProposalDecisionController::class, 'purchaseRequestComparison'])
                ->middleware('authorize:procurement.proposal_decisions.view')
                ->name('proposal_comparison');
            Route::post('/{id}/proposal-decision', [SupplierProposalDecisionController::class, 'selectForPurchaseRequest'])
                ->middleware('authorize:procurement.proposal_decisions.select')
                ->name('proposal_decision');
            Route::post('/{id}/approve', [PurchaseRequestController::class, 'approve'])
                ->middleware('authorize:procurement.purchase_requests.approve')
                ->name('approve');
            Route::post('/{id}/reject', [PurchaseRequestController::class, 'reject'])
                ->middleware('authorize:procurement.purchase_requests.reject')
                ->name('reject');
            Route::post('/{id}/create-order', fn () => AdminResponse::error(trans_message('procurement.purchase_orders.create_from_proposal_required'), 410))
                ->middleware('authorize:procurement.purchase_orders.create')
                ->name('create_order');
        });

        Route::prefix('supplier-requests')->name('supplier_requests.')->group(function () {
            Route::get('/', [SupplierRequestController::class, 'index'])
                ->middleware('authorize:procurement.supplier_requests.view')
                ->name('index');
            Route::post('/', [SupplierRequestController::class, 'store'])
                ->middleware('authorize:procurement.supplier_requests.create')
                ->name('store');
            Route::post('/bulk', [SupplierRequestController::class, 'bulkStore'])
                ->middleware('authorize:procurement.supplier_requests.create')
                ->name('bulk_store');
            Route::get('/{id}', [SupplierRequestController::class, 'show'])
                ->middleware('authorize:procurement.supplier_requests.view')
                ->name('show');
            Route::post('/{id}/send', [SupplierRequestController::class, 'send'])
                ->middleware('authorize:procurement.supplier_requests.send')
                ->name('send');
            Route::post('/{id}/cancel', [SupplierRequestController::class, 'cancel'])
                ->middleware('authorize:procurement.supplier_requests.cancel')
                ->name('cancel');
            Route::get('/{supplierRequest}/proposal-comparison', [SupplierProposalDecisionController::class, 'comparison'])
                ->middleware('authorize:procurement.proposal_decisions.view')
                ->name('proposal_comparison');
            Route::post('/{supplierRequest}/proposal-decision', [SupplierProposalDecisionController::class, 'select'])
                ->middleware('authorize:procurement.proposal_decisions.select')
                ->name('proposal_decision');
        });

        Route::prefix('purchase-orders')->name('purchase_orders.')->group(function () {
            Route::get('/', [PurchaseOrderController::class, 'index'])
                ->middleware('authorize:procurement.purchase_orders.view')
                ->name('index');
            Route::post('/', fn () => AdminResponse::error(trans_message('procurement.purchase_orders.create_from_proposal_required'), 410))
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
            Route::post('/{id}/receipt-document/pdf', [PurchaseOrderController::class, 'receiptDocumentPdf'])
                ->middleware('authorize:procurement.purchase_orders.receive')
                ->name('receipt_document_pdf');
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

        Route::get('/issues', [ProcurementIssueController::class, 'index'])
            ->middleware('authorize:procurement.view')
            ->name('issues.index');

        Route::get('/audit-logs', [PurchaseOrderController::class, 'auditLogs'])
            ->middleware('authorize:procurement.audit.view')
            ->name('audit_logs.index');

        Route::get('/audit-events', [ProcurementAuditEventController::class, 'index'])
            ->middleware('authorize:procurement.audit.view')
            ->name('audit_events.index');

        Route::prefix('approvals')->name('approvals.')->group(function () {
            Route::get('/', [ProcurementApprovalController::class, 'index'])
                ->middleware('authorize:procurement.approvals.view')
                ->name('index');
            Route::post('/{approval}/approve', [ProcurementApprovalController::class, 'approve'])
                ->middleware('authorize:procurement.approvals.resolve')
                ->name('approve');
            Route::post('/{approval}/reject', [ProcurementApprovalController::class, 'reject'])
                ->middleware('authorize:procurement.approvals.resolve')
                ->name('reject');
        });

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
