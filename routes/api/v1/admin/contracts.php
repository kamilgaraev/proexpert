<?php

use App\Http\Controllers\Api\V1\Admin\Contract\ContractPerformanceActController;
use App\Http\Controllers\Api\V1\Admin\ContractController;
use App\Http\Controllers\Api\V1\Admin\Contract\ContractTypeProfileController;
use Illuminate\Support\Facades\Route;

Route::post('contracts/template-card/prepare', [\App\Http\Controllers\Api\V1\Admin\Contract\ContractTemplateCardController::class, 'prepare'])
    ->middleware('authorize:contracts.create')->name('contracts.template-card.prepare');
Route::get('contracts/{contract}/template-card', [\App\Http\Controllers\Api\V1\Admin\Contract\ContractTemplateCardController::class, 'show'])
    ->whereNumber('contract')->middleware('authorize:contracts.view')->name('contracts.template-card.show');

Route::prefix('contract-library')->name('contracts.library.')->group(function (): void {
    Route::get('entities', [\App\Http\Controllers\Api\V1\Admin\Contract\ContractEntityController::class, 'index'])
        ->middleware('authorize:contracts.view')->name('entities');
    $controller = \App\Http\Controllers\Api\V1\Admin\Contract\ContractLibraryController::class;
    Route::get('', [$controller, 'index'])->middleware('authorize:contracts.library.view')->name('index');
    Route::post('definitions/read', [$controller, 'definitions'])->middleware('authorize:contracts.library.view')->name('definitions');
    Route::post('{item}/versions/{version}/calculate', [$controller, 'calculate'])->whereUuid('item')->whereNumber('version')
        ->middleware('authorize:contracts.library.view')->name('calculate');
    Route::get('{item}/versions/{version}/resolved', [$controller, 'resolved'])->whereUuid('item')->whereNumber('version')
        ->middleware('authorize:contracts.library.view')->name('resolved');
    Route::post('', [$controller, 'store'])->middleware('authorize:contracts.library.create')->name('store');
    Route::get('{item}/versions/{version}', [$controller, 'show'])->whereUuid('item')->whereNumber('version')
        ->middleware('authorize:contracts.library.view')->name('show');
    Route::post('{item}/versions', [$controller, 'revise'])->whereUuid('item')
        ->middleware('authorize:contracts.library.create')->name('revise');
    Route::post('{item}/versions/{version}/publish', [$controller, 'publish'])->whereUuid('item')->whereNumber('version')
        ->middleware('authorize:contracts.library.publish')->name('publish');
    Route::patch('{item}/archive', [$controller, 'archive'])->whereUuid('item')
        ->middleware('authorize:contracts.library.archive')->name('archive');
});
Route::prefix('contracts/{contract}/builder')->whereNumber('contract')->name('contracts.builder.')->group(function (): void {
    $controller = \App\Http\Controllers\Api\V1\Admin\Contract\ContractBuilderController::class;
    Route::get('', [$controller, 'state'])->middleware('authorize:contracts.view')->name('state');
    Route::post('', [$controller, 'store'])->middleware('authorize:contracts.edit')->name('store');
    $adoption = \App\Http\Controllers\Api\V1\Admin\Contract\ContractBuilderAdoptionController::class;
    Route::get('adoption-preview', [$adoption, 'preview'])->middleware('authorize:contracts.revisions.adopt')->name('adoption.preview');
    Route::post('adopt', [$adoption, 'store'])->middleware('authorize:contracts.revisions.adopt')->name('adoption.store');
    $proposals = \App\Http\Controllers\Api\V1\Admin\Contract\ContractProposalController::class;
    Route::get('proposals', [$proposals, 'index'])->middleware('authorize:contracts.view')->name('proposals.index');
    Route::post('proposals', [$proposals, 'store'])->middleware('authorize:contracts.edit')->name('proposals.store');
    Route::get('proposals/{proposal}', [$proposals, 'show'])->whereNumber('proposal')->middleware('authorize:contracts.view')->name('proposals.show');
    Route::get('proposals/{proposal}/preview', [$proposals, 'preview'])->whereNumber('proposal')->middleware('authorize:contracts.view')->name('proposals.preview');
    Route::post('proposals/{proposal}/decision', [$proposals, 'decide'])->whereNumber('proposal')->middleware('authorize:contracts.revisions.accept')->name('proposals.decide');
    Route::post('proposals/{proposal}/external-decision', [$proposals, 'externalDecision'])->whereNumber('proposal')->middleware('authorize:contracts.revisions.record_external')->name('proposals.external-decision');
    $assets = \App\Http\Controllers\Api\V1\Admin\Contract\ContractBuilderAssetController::class;
    Route::post('assets', [$assets, 'store'])->middleware('authorize:contracts.view')->name('assets.store');
    Route::get('assets/{asset}/download', [$assets, 'download'])->whereNumber('asset')->middleware('authorize:contracts.view')->name('assets.download');
    $draftController = \App\Http\Controllers\Api\V1\Admin\Contract\ContractBuilderDraftController::class;
    Route::get('draft', [$draftController, 'show'])->middleware('authorize:contracts.view')->name('draft.show');
    Route::put('draft', [$draftController, 'store'])->middleware('authorize:contracts.edit')->name('draft.store');
    Route::get('draft/preview', [$draftController, 'preview'])->middleware('authorize:contracts.view')->name('draft.preview');
    Route::get('draft/source-changes', [$draftController, 'sourceChanges'])->middleware('authorize:contracts.edit')->name('draft.source-changes');
    Route::get('revisions/{revision}', [$controller, 'show'])->whereNumber('revision')->middleware('authorize:contracts.view')->name('show');
    $confirmations = \App\Http\Controllers\Api\V1\Admin\Contract\ContractRevisionConfirmationController::class;
    Route::get('revisions/{revision}/confirmations', [$confirmations, 'show'])->whereNumber('revision')->middleware('authorize:contracts.view')->name('confirmations.show');
    Route::post('revisions/{revision}/confirmations', [$confirmations, 'store'])->whereNumber('revision')->middleware('authorize:contracts.revisions.confirm')->name('confirmations.store');
    Route::post('revisions/{revision}/external-confirmation', [$confirmations, 'external'])->whereNumber('revision')->middleware('authorize:contracts.revisions.record_external')->name('confirmations.external');
    $archive = \App\Http\Controllers\Api\V1\Admin\Contract\ContractRevisionArchiveController::class;
    $activation = \App\Http\Controllers\Api\V1\Admin\Contract\ContractRevisionActivationController::class;
    Route::get('activations', [$activation, 'index'])->middleware('authorize:contracts.view')->name('activations.index');
    Route::get('activations/{activation}', [$activation, 'show'])->whereNumber('activation')->middleware('authorize:contracts.view')->name('activations.show');
    Route::post('activations/{activation}/retry', [$activation, 'retry'])->whereNumber('activation')->middleware('authorize:contracts.revisions.activate')->name('activations.retry');
    Route::post('activations/{activation}/cancel', [$activation, 'cancel'])->whereNumber('activation')->middleware('authorize:contracts.revisions.activate')->name('activations.cancel');
    Route::get('revisions/{revision}/activation-preview', [$activation, 'preview'])->whereNumber('revision')->middleware('authorize:contracts.view')->name('activations.preview');
    Route::post('revisions/{revision}/activate', [$activation, 'store'])->whereNumber('revision')->middleware('authorize:contracts.revisions.activate')->name('activations.store');
    $paymentPlan = \App\BusinessModules\Core\Payments\Http\Controllers\ContractRevisionPaymentPlanController::class;
    Route::get('payment-plans', [$paymentPlan, 'index'])->middleware('authorize:payments.schedule.view')->name('payment-plans.index');
    Route::post('activations/{activation}/payment-plan', [$paymentPlan, 'store'])->whereNumber('activation')->middleware('authorize:payments.schedule.create')->name('payment-plans.store');
    Route::get('revisions/{revision}/legal-archive', [$archive, 'show'])->whereNumber('revision')->middleware('authorize:contracts.view')->name('legal-archive.show');
    Route::post('revisions/{revision}/legal-archive', [$archive, 'store'])->whereNumber('revision')->middleware('authorize:contracts.edit')->name('legal-archive.store');
    Route::get('revisions/{revision}/preview', [$controller, 'preview'])->whereNumber('revision')->middleware('authorize:contracts.view')->name('preview');
    Route::post('revisions/{revision}/export', [\App\Http\Controllers\Api\V1\Admin\Contract\ContractBuilderExportController::class, 'store'])
        ->whereNumber('revision')->middleware('authorize:contracts.view')->name('export');
});

// ContractPaymentController удален - используйте модуль Payments

// Маршруты для Контрактов
// Префикс 'admin' и middleware применяются в главном файле routes/api.php
Route::get('contracts', [ContractController::class, 'index'])
    ->middleware('authorize:contracts.view')
    ->name('contracts.index');
Route::post('contracts', [ContractController::class, 'store'])
    ->middleware('authorize:contracts.create')
    ->name('contracts.store');
Route::get('contracts/type-profiles', [ContractTypeProfileController::class, 'index'])
    ->middleware('authorize:contracts.create')
    ->name('contracts.type-profiles');
Route::get('contracts/party-preview', \App\Http\Controllers\Api\V1\Admin\Contract\ContractPartyPreviewController::class)
    ->middleware('authorize:contracts.create')
    ->name('contracts.party-preview');
Route::get('contracts/organization-views', [\App\Http\Controllers\Api\V1\Admin\Contract\ContractOrganizationViewController::class, 'index'])
    ->middleware('authorize:contracts.view')->name('contracts.organization-view.index');
Route::get('contracts/{contract}', [ContractController::class, 'show'])
    ->middleware('authorize:contracts.view')
    ->name('contracts.show');
Route::get('contracts/{contract}/organization-view', [\App\Http\Controllers\Api\V1\Admin\Contract\ContractOrganizationViewController::class, 'show'])
    ->middleware('authorize:contracts.view')->name('contracts.organization-view.show');
Route::get('contracts/{contract}/organization-view/history', [\App\Http\Controllers\Api\V1\Admin\Contract\ContractOrganizationViewController::class, 'history'])
    ->middleware('authorize:contracts.view')->name('contracts.organization-view.history');
Route::get('contracts/{contract}/organization-view/enrollment-preview', [\App\Http\Controllers\Api\V1\Admin\Contract\ContractOrganizationEnrollmentController::class, 'preview'])
    ->middleware('authorize:contracts.edit')->name('contracts.organization-view.enrollment-preview');
Route::post('contracts/{contract}/organization-view/enroll', [\App\Http\Controllers\Api\V1\Admin\Contract\ContractOrganizationEnrollmentController::class, 'enable'])
    ->middleware('authorize:contracts.edit')->name('contracts.organization-view.enroll');
Route::patch('contracts/{contract}/organization-view', [\App\Http\Controllers\Api\V1\Admin\Contract\ContractOrganizationViewController::class, 'update'])
    ->middleware('authorize:contracts.edit')->name('contracts.organization-view.update');
Route::post('contracts/{contract}/organization-view/transition', [\App\Http\Controllers\Api\V1\Admin\Contract\ContractOrganizationViewController::class, 'transition'])
    ->middleware('authorize:contracts.view')->name('contracts.organization-view.transition');
Route::match(['put', 'patch'], 'contracts/{contract}', [ContractController::class, 'update'])
    ->middleware('authorize:contracts.edit')
    ->name('contracts.update');
Route::delete('contracts/{contract}', [ContractController::class, 'destroy'])
    ->middleware('authorize:contracts.delete')
    ->name('contracts.destroy');

foreach (['activate', 'suspend', 'resume', 'complete', 'terminate'] as $action) {
    Route::post("contracts/{contract}/{$action}", [ContractController::class, 'transition'])
        ->defaults('action', $action)
        ->middleware('authorize:contracts.edit')
        ->name("contracts.{$action}");
}

Route::post('contracts/{contract}/archive', [ContractController::class, 'transition'])
    ->defaults('action', 'archive')
    ->middleware('authorize:contracts.archive')
    ->name('contracts.archive');

// Дополнительные маршруты для контрактов
Route::group(['prefix' => 'contracts'], function () {
    Route::get('{contract}/full', [ContractController::class, 'fullDetails'])
        ->middleware('authorize:contracts.view')
        ->name('contracts.full-details');
    Route::post('{contract}/resolve-side-review', [ContractController::class, 'resolveSideReview'])
        ->middleware('authorize:contracts.edit')
        ->name('contracts.resolve-side-review');
    Route::get('{contract}/analytics', [ContractController::class, 'analytics'])
        ->middleware('authorize:contracts.view')
        ->name('contracts.analytics');
    Route::get('{contract}/completed-works', [ContractController::class, 'completedWorks'])
        ->middleware('authorize:contracts.view')
        ->name('contracts.completed-works');
    Route::get('{contract}/export-ks6a', [ContractController::class, 'exportKS6a'])
        ->middleware('authorize:contracts.performance_acts.export')
        ->name('contracts.export-ks6a');
});

// Вложенные маршруты для Актов выполненных работ к Контрактам
// Имена параметров будут contract и performance_act
// Доступ: admin/contracts/{contract}/performance-acts
//         admin/performance-acts/{performance_act} (благодаря shallow)
Route::get('contracts/{contract}/performance-acts', [ContractPerformanceActController::class, 'index'])
    ->middleware('authorize:contracts.performance_acts.view')
    ->name('contracts.performance-acts.index');
Route::get('performance-acts/{performance_act}', [ContractPerformanceActController::class, 'show'])
    ->middleware('authorize:contracts.performance_acts.view')
    ->name('performance-acts.show');
Route::match(['put', 'patch'], 'performance-acts/{performance_act}', [ContractPerformanceActController::class, 'update'])
    ->middleware('authorize:contracts.performance_acts.edit')
    ->name('performance-acts.update');
Route::delete('performance-acts/{performance_act}', [ContractPerformanceActController::class, 'destroy'])
    ->middleware('authorize:contracts.performance_acts.delete')
    ->name('performance-acts.destroy');

// Дополнительные маршруты для экспорта актов и файлов
Route::group(['prefix' => 'contracts/{contract}/performance-acts'], function () {
    Route::get('{performance_act}/export/pdf', [ContractPerformanceActController::class, 'exportPdf'])
        ->middleware('authorize:contracts.performance_acts.export')
        ->name('contracts.performance-acts.export.pdf');
    Route::get('{performance_act}/export/excel', [ContractPerformanceActController::class, 'exportExcel'])
        ->middleware('authorize:contracts.performance_acts.export')
        ->name('contracts.performance-acts.export.excel');
    Route::get('{performance_act}/export/ks3', [ContractPerformanceActController::class, 'exportKS3'])
        ->middleware('authorize:contracts.performance_acts.export')
        ->name('contracts.performance-acts.export.ks3');
});

// Маршруты для файлов актов (shallow - без привязки к контракту)
Route::get('performance-acts/{performance_act}/files', [ContractPerformanceActController::class, 'getFiles'])
    ->middleware('authorize:contracts.performance_acts.view')
    ->name('performance-acts.files');

// УСТАРЕВШИЕ МАРШРУТЫ - УДАЛЕНЫ
// Платежи по контрактам теперь управляются через модуль Payments
// Используйте: /api/v1/admin/payments/invoices
// Старые маршруты contracts.payments больше не поддерживаются

// ПРИМЕЧАНИЕ: Маршруты для спецификаций и state-events перенесены в project-based.php
// Используйте маршруты: /api/v1/admin/projects/{project}/contracts/{contract}/...

// Маршруты для распределения контрактов по проектам (allocations)
require __DIR__.'/contract_allocations.php';
