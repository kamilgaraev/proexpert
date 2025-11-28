<?php

namespace App\BusinessModules\Features\SiteRequests\Listeners;

use App\BusinessModules\Core\Payments\Events\PaymentDocumentPaid;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum;
use App\BusinessModules\Features\SiteRequests\Services\SiteRequestService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Слушатель для автоматического завершения заявок при оплате платежа
 */
class CompleteSiteRequestsOnPaymentPaid implements ShouldQueue
{
    /**
     * Create the event listener.
     */
    public function __construct(
        private readonly SiteRequestService $siteRequestService
    ) {}

    /**
     * Handle the event.
     */
    public function handle(PaymentDocumentPaid $event): void
    {
        $paymentDocument = $event->document;

        // Загружаем связанные заявки
        $siteRequests = $paymentDocument->siteRequests;

        if ($siteRequests->isEmpty()) {
            return; // Нет связанных заявок
        }

        Log::info('site_request.payment.paid.completing_requests', [
            'payment_document_id' => $paymentDocument->id,
            'site_request_ids' => $siteRequests->pluck('id')->toArray(),
        ]);

        // Меняем статус всех связанных заявок на COMPLETED
        foreach ($siteRequests as $siteRequest) {
            try {
                // Проверяем, что заявка еще не завершена
                if ($siteRequest->status === SiteRequestStatusEnum::COMPLETED) {
                    continue;
                }

                // Используем сервис для изменения статуса с соблюдением workflow
                // Переход APPROVED -> COMPLETED теперь разрешен в базовом workflow
                // Но если организация настроила кастомные переходы, которые запрещают это,
                // делаем прямое обновление (оплата платежа - финальное действие)
                try {
                    $this->siteRequestService->changeStatus(
                        $siteRequest,
                        auth()->id() ?? $paymentDocument->created_by_user_id ?? 1,
                        SiteRequestStatusEnum::COMPLETED->value,
                        "Заявка автоматически завершена после оплаты платежа №{$paymentDocument->document_number}"
                    );
                } catch (\DomainException $e) {
                    // Если кастомный workflow запрещает переход, делаем прямое обновление
                    // Это допустимо, так как оплата платежа - это финальное действие
                    \Log::warning('site_request.complete_on_payment.workflow_blocked', [
                        'site_request_id' => $siteRequest->id,
                        'current_status' => $siteRequest->status->value,
                        'error' => $e->getMessage(),
                    ]);

                    $oldStatus = $siteRequest->status->value;
                    $siteRequest->update([
                        'status' => SiteRequestStatusEnum::COMPLETED->value,
                        'notes' => ($siteRequest->notes ? $siteRequest->notes . "\n\n" : '') .
                                   "Заявка автоматически завершена после оплаты платежа №{$paymentDocument->document_number}",
                    ]);

                    // Записываем в историю
                    \App\BusinessModules\Features\SiteRequests\Models\SiteRequestHistory::logStatusChanged(
                        $siteRequest,
                        $paymentDocument->created_by_user_id ?? 1,
                        $oldStatus,
                        SiteRequestStatusEnum::COMPLETED->value,
                        "Автоматически завершена после оплаты платежа"
                    );

                    // Отправляем событие для уведомлений
                    event(new \App\BusinessModules\Features\SiteRequests\Events\SiteRequestStatusChanged(
                        $siteRequest,
                        $oldStatus,
                        SiteRequestStatusEnum::COMPLETED->value,
                        $paymentDocument->created_by_user_id ?? 1
                    ));
                }

                Log::info('site_request.completed_on_payment', [
                    'site_request_id' => $siteRequest->id,
                    'payment_document_id' => $paymentDocument->id,
                ]);
            } catch (\Exception $e) {
                Log::error('site_request.complete_on_payment.failed', [
                    'site_request_id' => $siteRequest->id,
                    'payment_document_id' => $paymentDocument->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }
    }
}

