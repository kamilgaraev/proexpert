<?php

namespace App\BusinessModules\Core\Payments\Listeners;

use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Services\PaymentDocumentService;
use App\Models\Act;
use Illuminate\Support\Facades\Log;

class AutoCreateInvoiceFromAct
{
    public function __construct(
        private readonly PaymentDocumentService $documentService
    ) {}

    /**
     * Handle the event.
     */
    public function handle($event): void
    {
        // Проверяем настройку автосоздания
        if (!config('payments.auto_create_invoices.from_acts', false)) {
            return;
        }

        $act = $this->extractActFromEvent($event);
        
        if (!$act) {
            return;
        }

        try {
            // Проверяем что акт подписан обеими сторонами
            if (!$this->isActFullySigned($act)) {
                Log::info('payment_auto_create.act_not_signed', [
                    'act_id' => $act->id,
                ]);
                return;
            }

            // Проверяем что счет еще не создан
            if ($this->invoiceAlreadyExists($act)) {
                Log::info('payment_auto_create.invoice_exists', [
                    'act_id' => $act->id,
                ]);
                return;
            }

            // Создаем платежный документ
            $document = $this->documentService->create([
                'organization_id' => $act->organization_id,
                'project_id' => $act->project_id,
                'document_type' => PaymentDocumentType::INVOICE->value,
                'document_date' => $act->act_date ?? now(),
                'due_date' => $this->calculateDueDate($act),
                
                // Плательщик - организация (если акт от подрядчика)
                'payer_organization_id' => $act->organization_id,
                'payer_contractor_id' => null,
                
                // Получатель - подрядчик
                'payee_organization_id' => null,
                'payee_contractor_id' => $act->contractor_id,
                
                'amount' => $act->total_amount,
                'currency' => 'RUB',
                'vat_rate' => $act->vat_rate ?? 20,
                
                'source_type' => Act::class,
                'source_id' => $act->id,
                
                'description' => "Счет на оплату по акту выполненных работ {$act->act_number}",
                'payment_purpose' => $this->generatePaymentPurpose($act),
                
                'metadata' => [
                    'auto_created' => true,
                    'created_from_act' => true,
                    'act_id' => $act->id,
                    'act_number' => $act->act_number,
                ],
            ]);

            Log::info('payment_auto_create.invoice_created', [
                'act_id' => $act->id,
                'document_id' => $document->id,
                'document_number' => $document->document_number,
            ]);

        } catch (\Exception $e) {
            Log::error('payment_auto_create.failed', [
                'act_id' => $act->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Извлечь акт из события
     */
    private function extractActFromEvent($event): ?Act
    {
        // Поддержка разных форматов событий
        if ($event instanceof Act) {
            return $event;
        }

        if (isset($event->act) && $event->act instanceof Act) {
            return $event->act;
        }

        if (isset($event->model) && $event->model instanceof Act) {
            return $event->model;
        }

        return null;
    }

    /**
     * Проверить что акт подписан
     */
    private function isActFullySigned(Act $act): bool
    {
        // Проверяем статус акта
        if (isset($act->status)) {
            return in_array($act->status, ['signed', 'approved', 'completed']);
        }

        // Проверяем наличие подписей
        if (isset($act->customer_signed) && isset($act->contractor_signed)) {
            return $act->customer_signed && $act->contractor_signed;
        }

        // По умолчанию считаем что акт готов к оплате
        return true;
    }

    /**
     * Проверить что счет уже создан
     */
    private function invoiceAlreadyExists(Act $act): bool
    {
        return \App\BusinessModules\Core\Payments\Models\PaymentDocument
            ::where('source_type', Act::class)
            ->where('source_id', $act->id)
            ->exists();
    }

    /**
     * Рассчитать срок оплаты
     */
    private function calculateDueDate(Act $act): \Carbon\Carbon
    {
        $contract = $act->contract;
        
        if ($contract && isset($contract->payment_terms_days)) {
            return now()->addDays($contract->payment_terms_days);
        }

        // По умолчанию 14 дней
        return now()->addDays(14);
    }

    /**
     * Генерация назначения платежа
     */
    private function generatePaymentPurpose(Act $act): string
    {
        $parts = [];
        
        $parts[] = "Оплата по акту выполненных работ {$act->act_number}";
        
        if ($act->act_date) {
            $parts[] = "от " . $act->act_date->format('d.m.Y');
        }

        $contract = $act->contract;
        if ($contract) {
            $parts[] = "по договору {$contract->contract_number}";
        }

        $vatRate = $act->vat_rate ?? 20;
        if ($vatRate > 0) {
            $parts[] = "В том числе НДС {$vatRate}%";
        } else {
            $parts[] = "Без НДС";
        }

        return implode('. ', $parts);
    }
}

