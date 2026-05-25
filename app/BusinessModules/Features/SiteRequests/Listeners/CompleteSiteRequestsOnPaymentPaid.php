<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SiteRequests\Listeners;

use App\BusinessModules\Core\Payments\Events\PaymentDocumentPaid;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum;
use App\BusinessModules\Features\SiteRequests\Services\SiteRequestService;
use DomainException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class CompleteSiteRequestsOnPaymentPaid implements ShouldQueue
{
    public function __construct(
        private readonly SiteRequestService $siteRequestService
    ) {}

    public function handle(PaymentDocumentPaid $event): void
    {
        $paymentDocument = $event->document;
        $siteRequests = $paymentDocument->siteRequests;

        if ($siteRequests->isEmpty()) {
            return;
        }

        Log::info('site_request.payment.paid.completing_requests', [
            'payment_document_id' => $paymentDocument->id,
            'site_request_ids' => $siteRequests->pluck('id')->toArray(),
        ]);

        foreach ($siteRequests as $siteRequest) {
            if ($siteRequest->status === SiteRequestStatusEnum::COMPLETED) {
                continue;
            }

            $userId = auth()->id() ?? $paymentDocument->created_by_user_id ?? $siteRequest->user_id;

            if (!$userId) {
                Log::warning('site_request.complete_on_payment.user_missing', [
                    'site_request_id' => $siteRequest->id,
                    'payment_document_id' => $paymentDocument->id,
                ]);

                continue;
            }

            try {
                $this->siteRequestService->changeStatus(
                    $siteRequest,
                    (int) $userId,
                    SiteRequestStatusEnum::COMPLETED->value
                );

                Log::info('site_request.completed_on_payment', [
                    'site_request_id' => $siteRequest->id,
                    'payment_document_id' => $paymentDocument->id,
                ]);
            } catch (DomainException $e) {
                Log::warning('site_request.complete_on_payment.workflow_blocked', [
                    'site_request_id' => $siteRequest->id,
                    'payment_document_id' => $paymentDocument->id,
                    'current_status' => $siteRequest->status->value,
                    'error' => $e->getMessage(),
                ]);
            } catch (\Throwable $e) {
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
