<?php

namespace App\BusinessModules\Features\Procurement\Listeners;

use App\BusinessModules\Features\SiteRequests\Events\SiteRequestApproved;
use App\BusinessModules\Features\Procurement\Services\PurchaseRequestService;
use App\Modules\Core\AccessController;

/**
 * Слушатель для автоматического создания заявки на закупку
 * при одобрении заявки с объекта на материалы
 */
class CreatePurchaseRequestFromSiteRequest
{
    public function __construct(
        private readonly PurchaseRequestService $purchaseRequestService,
        private readonly AccessController $accessController
    ) {}

    /**
     * Handle the event.
     */
    public function handle(SiteRequestApproved $event): void
    {
        $siteRequest = $event->siteRequest;

        // Проверяем, что это заявка на материалы
        if ($siteRequest->request_type->value !== 'material') {
            return;
        }

        // Проверяем активацию модуля procurement
        if (!$this->accessController->hasModuleAccess($siteRequest->organization_id, 'procurement')) {
            \Log::info('procurement.skip_auto_create', [
                'site_request_id' => $siteRequest->id,
                'reason' => 'Модуль закупок не активирован',
            ]);
            return;
        }

        // Проверяем настройки модуля
        $module = app(\App\BusinessModules\Features\Procurement\ProcurementModule::class);
        $settings = $module->getSettings($siteRequest->organization_id);

        if (!($settings['auto_create_purchase_request'] ?? true)) {
            \Log::info('procurement.skip_auto_create', [
                'site_request_id' => $siteRequest->id,
                'reason' => 'Автоматическое создание отключено в настройках',
            ]);
            return;
        }

        try {
            // Создаем заявку на закупку
            $purchaseRequest = $this->purchaseRequestService->createFromSiteRequest($siteRequest);

            \Log::info('procurement.purchase_request.auto_created', [
                'site_request_id' => $siteRequest->id,
                'purchase_request_id' => $purchaseRequest->id,
            ]);
        } catch (\Exception $e) {
            \Log::error('procurement.purchase_request.auto_create_failed', [
                'site_request_id' => $siteRequest->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

