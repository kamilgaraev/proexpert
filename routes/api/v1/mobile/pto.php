<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Mobile\MobilePtoController;
use Illuminate\Support\Facades\Route;

Route::get('/pto/design-packages', [MobilePtoController::class, 'designPackages'])
    ->name('pto.design-packages.index');
Route::get('/pto/design-packages/{packageId}', [MobilePtoController::class, 'showDesignPackage'])
    ->whereNumber('packageId')
    ->name('pto.design-packages.show');
Route::post('/pto/design-packages/{packageId}/actions/{action}', [MobilePtoController::class, 'actOnDesignPackage'])
    ->whereNumber('packageId')
    ->whereIn('action', ['submit_norm_control', 'return_to_work', 'submit_customer_review', 'approve', 'issue', 'archive'])
    ->name('pto.design-packages.actions');
Route::post('/pto/executive-documents/{documentId}/actions/{action}', [MobilePtoController::class, 'actOnExecutiveDocument'])
    ->whereNumber('documentId')
    ->whereIn('action', ['submit', 'approve', 'reject', 'add_remark'])
    ->name('pto.executive-documents.actions');
