<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Services;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Models\ContractPerformanceAct;
use Brick\Math\BigDecimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class PaymentDocumentForecastQuery
{
    public function forProject(int $projectId, ?int $organizationId): array
    {
        $matches = PaymentRequestOrderQuery::matchingOrderSql(
            'linked_request', 'payment_documents', 'linked_request_act', 'document_act',
        );
        $rows = DB::table('payment_documents')
            ->leftJoin('payment_documents as linked_request', function ($join): void {
                $join->on('linked_request.id', '=', 'payment_documents.source_id')
                    ->where('payment_documents.source_type', PaymentDocument::class);
            })
            ->leftJoin('contract_performance_acts as document_act', function ($join): void {
                $join->on('document_act.id', '=', 'payment_documents.invoiceable_id')
                    ->where('payment_documents.invoiceable_type', ContractPerformanceAct::class);
            })
            ->leftJoin('contract_performance_acts as linked_request_act', function ($join): void {
                $join->on('linked_request_act.id', '=', 'linked_request.invoiceable_id')
                    ->where('linked_request.invoiceable_type', ContractPerformanceAct::class);
            })
            ->where('payment_documents.project_id', $projectId)
            ->whereNull('payment_documents.deleted_at')
            ->whereNotIn('payment_documents.status', ['cancelled', 'rejected'])
            ->whereIn('payment_documents.direction', ['incoming', 'outgoing'])
            ->when($organizationId !== null, static fn (Builder $query): Builder => $query
                ->where('payment_documents.organization_id', $organizationId))
            ->select(['payment_documents.id', 'payment_documents.amount', 'payment_documents.remaining_amount',
                'payment_documents.direction', 'payment_documents.due_date'])
            ->selectRaw("CASE WHEN linked_request.document_type = 'payment_request' AND linked_request.amount > 0 "
                ."AND linked_request.deleted_at IS NULL AND linked_request.status NOT IN ('cancelled', 'rejected') "
                ."AND linked_request.direction IN ('incoming', 'outgoing') AND ({$matches}) "
                .'THEN linked_request.id ELSE NULL END AS matched_request_id')
            ->orderBy('payment_documents.id')->get();

        $documents = [];
        $remaining = [];
        $ordersByRequest = [];
        foreach ($rows as $row) {
            $id = (int) $row->id;
            $documents[$id] = $row;
            $remaining[$id] = BigDecimal::max('0', (string) $row->remaining_amount);
            if ($row->matched_request_id !== null) {
                $ordersByRequest[(int) $row->matched_request_id][] = $id;
            }
        }

        foreach ($ordersByRequest as $requestId => $orderIds) {
            if (! isset($documents[$requestId])) {
                continue;
            }
            $coverage = BigDecimal::zero();
            foreach ($orderIds as $orderId) {
                $coverage = $coverage->plus(BigDecimal::max('0', (string) $documents[$orderId]->amount));
            }
            $requestRemaining = $remaining[$requestId];
            $remaining[$requestId] = BigDecimal::max('0', $requestRemaining->minus($coverage));
            $credit = BigDecimal::max('0', BigDecimal::min($coverage, (string) $documents[$requestId]->amount)
                ->minus($requestRemaining));
            usort($orderIds, static fn (int $first, int $second): int => [$documents[$first]->due_date ?? '9999-12-31', $first]
                <=> [$documents[$second]->due_date ?? '9999-12-31', $second]);
            foreach ($orderIds as $orderId) {
                if (! $credit->isPositive()) {
                    break;
                }
                $applied = BigDecimal::min($remaining[$orderId], $credit);
                $remaining[$orderId] = $remaining[$orderId]->minus($applied);
                $credit = $credit->minus($applied);
            }
        }

        $facts = [];
        foreach ($documents as $id => $row) {
            if ($remaining[$id]->isPositive()) {
                $facts[] = [
                    'direction' => (string) $row->direction,
                    'amount' => $remaining[$id]->toFloat(),
                    'due_at' => (string) $row->due_date,
                ];
            }
        }

        return $facts;
    }
}
