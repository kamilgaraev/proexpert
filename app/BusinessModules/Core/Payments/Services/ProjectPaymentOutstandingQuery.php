<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Services;

use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Models\ContractPerformanceAct;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ProjectPaymentOutstandingQuery
{
    private const COVERAGE_STATUSES = [
        PaymentDocumentStatus::SUBMITTED->value,
        PaymentDocumentStatus::PENDING_APPROVAL->value,
        PaymentDocumentStatus::APPROVED->value,
        PaymentDocumentStatus::SCHEDULED->value,
        PaymentDocumentStatus::PARTIALLY_PAID->value,
        PaymentDocumentStatus::PAID->value,
    ];

    public function forProject(int $organizationId, int $projectId): Collection
    {
        $documents = DB::table('payment_documents')
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->whereNull('deleted_at')
            ->get([
                'id', 'document_number', 'amount', 'paid_amount', 'remaining_amount',
                'status', 'direction', 'due_date', 'scheduled_at', 'paid_at',
            ]);
        $byId = $documents->keyBy('id');
        $links = DB::table('payment_documents as payment_request')
            ->leftJoin('contract_performance_acts as request_act', function (JoinClause $join): void {
                $join->on('request_act.id', '=', 'payment_request.invoiceable_id')
                    ->where('payment_request.invoiceable_type', ContractPerformanceAct::class);
            })
            ->join('payment_documents as linked_order', function (JoinClause $join): void {
                $join->on('linked_order.source_id', '=', 'payment_request.id')
                    ->where('linked_order.source_type', PaymentDocument::class);
            })
            ->leftJoin('contract_performance_acts as linked_order_act', function (JoinClause $join): void {
                $join->on('linked_order_act.id', '=', 'linked_order.invoiceable_id')
                    ->where('linked_order.invoiceable_type', ContractPerformanceAct::class);
            })
            ->where('payment_request.organization_id', $organizationId)
            ->where('payment_request.project_id', $projectId)
            ->whereNull('payment_request.deleted_at')
            ->where('payment_request.document_type', PaymentDocumentType::PAYMENT_REQUEST->value)
            ->where('payment_request.amount', '>', 0)
            ->whereIn('payment_request.status', self::COVERAGE_STATUSES)
            ->where('linked_order.amount', '>', 0)
            ->whereIn('linked_order.status', self::COVERAGE_STATUSES)
            ->whereRaw(PaymentRequestOrderQuery::matchingOrderSql('payment_request', 'linked_order', 'request_act', 'linked_order_act'))
            ->get(['payment_request.id as request_id', 'linked_order.id as order_id']);

        foreach ($links->groupBy('request_id') as $requestId => $requestLinks) {
            $request = $byId->get($requestId);
            if ($request === null) {
                continue;
            }

            $orders = [];
            foreach ($requestLinks as $link) {
                $order = $byId->get($link->order_id);
                if ($order !== null) {
                    $orders[] = $order;
                }
            }
            usort($orders, static fn (object $left, object $right): int => [$left->due_date ?? '9999-12-31', $left->id] <=> [$right->due_date ?? '9999-12-31', $right->id]);
            $coverage = array_sum(array_map(static fn (object $order): float => max((float) $order->amount, 0), $orders));
            $remaining = $this->remaining($request);
            $request->remaining_amount = round(max($remaining - $coverage, 0), 2);
            $paymentCredit = round(max(min((float) $request->amount, $coverage) - $remaining, 0), 2);

            foreach ($orders as $order) {
                $orderRemaining = $this->remaining($order);
                $appliedCredit = min($paymentCredit, $orderRemaining);
                $order->remaining_amount = round($orderRemaining - $appliedCredit, 2);
                $paymentCredit = round($paymentCredit - $appliedCredit, 2);
            }
        }

        return $documents;
    }

    private function remaining(object $document): float
    {
        return max((float) ($document->remaining_amount ?? ((float) $document->amount - (float) $document->paid_amount)), 0);
    }
}
