<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\Services;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Models\ProcurementProcessEvent;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class ProcurementProcessEventRecorder
{
    private const EVENT_STAGES = [
        'request_created' => 'request',
        'request_approved' => 'approval',
        'solicitation_sent' => 'solicitation',
        'supplier_responded' => 'solicitation',
        'award_decided' => 'award',
        'order_sent' => 'order',
        'first_receipt' => 'receipt',
        'fully_received' => 'receipt',
        'cancelled' => 'cancelled',
    ];

    public function record(
        int $organizationId,
        int $purchaseRequestId,
        int $purchaseRequestLineId,
        string $eventCode,
        string $stage,
        DateTimeInterface $occurredAt,
        int $eventVersion,
        string $sourceEventId,
        ?int $actorId = null,
        ?int $supplierRequestId = null,
        ?int $supplierProposalVersionId = null,
        ?int $purchaseOrderId = null,
        ?int $purchaseReceiptId = null,
        array $evidence = [],
        ?int $projectId = null,
    ): ProcurementProcessEvent {
        if (! in_array($eventCode, ProcurementProcessEvent::EVENT_CODES, true)) {
            throw new DomainException('Unsupported procurement process event code.');
        }
        if ($organizationId < 1 || $purchaseRequestId < 1 || $purchaseRequestLineId < 1 || $eventVersion < 1) {
            throw new DomainException('Procurement process event identity is invalid.');
        }
        if ((self::EVENT_STAGES[$eventCode] ?? null) !== $stage
            || trim($sourceEventId) === ''
            || strlen($sourceEventId) > 128) {
            throw new DomainException('Procurement process event stage or source identity is invalid.');
        }
        $owner = DB::table('purchase_request_lines as event_owner_line')
            ->join('purchase_requests as event_owner_request', 'event_owner_request.id', '=', 'event_owner_line.purchase_request_id')
            ->join('site_requests as event_owner_site_request', 'event_owner_site_request.id', '=', 'event_owner_request.site_request_id')
            ->leftJoin('materials as event_owner_material', 'event_owner_material.id', '=', 'event_owner_line.material_id')
            ->where('event_owner_line.id', $purchaseRequestLineId)
            ->where('event_owner_request.id', $purchaseRequestId)
            ->first([
                'event_owner_site_request.project_id',
                'event_owner_site_request.user_id as requester_id',
                'event_owner_request.assigned_to as buyer_id',
                'event_owner_line.material_id',
                'event_owner_material.category',
                'event_owner_request.budget_amount',
                'event_owner_request.budget_currency',
                'event_owner_site_request.priority',
            ]);
        if ($owner === null) {
            throw new DomainException('Procurement process event owner is unavailable.');
        }
        $supplierPartyId = $purchaseOrderId === null
            ? DB::table('supplier_requests')->where('id', $supplierRequestId)->value('supplier_party_id')
            : DB::table('purchase_orders')->where('id', $purchaseOrderId)->value('supplier_party_id');
        $evidence = array_merge($evidence, [
            'project_id' => $owner->project_id,
            'requester_id' => $owner->requester_id,
            'buyer_id' => $owner->buyer_id,
            'material_id' => $owner->material_id,
            'category' => $owner->category,
            'amount' => (string) $owner->budget_amount,
            'currency' => $owner->budget_currency,
            'priority' => $owner->priority,
            'supplier_party_id' => $supplierPartyId,
        ]);

        $attributes = [
            'organization_id' => $organizationId,
            'purchase_request_id' => $purchaseRequestId,
            'purchase_request_line_id' => $purchaseRequestLineId,
            'project_id' => $projectId,
            'supplier_request_id' => $supplierRequestId,
            'supplier_proposal_version_id' => $supplierProposalVersionId,
            'purchase_order_id' => $purchaseOrderId,
            'purchase_receipt_id' => $purchaseReceiptId,
            'event_code' => $eventCode,
            'stage' => $stage,
            'actor_id' => $actorId,
            'occurred_at' => $occurredAt,
            'event_version' => $eventVersion,
            'source_event_id' => $sourceEventId,
            'evidence' => $evidence,
        ];
        $attributes['source_hash'] = hash('sha256', CanonicalJson::encode($this->canonical($attributes)));

        return DB::transaction(function () use (
            $attributes,
            $eventCode,
            $occurredAt,
            $organizationId,
            $purchaseRequestLineId,
            $sourceEventId,
        ): ProcurementProcessEvent {
            $existing = ProcurementProcessEvent::query()
                ->where('organization_id', $organizationId)
                ->where('purchase_request_line_id', $purchaseRequestLineId)
                ->where('event_code', $eventCode)
                ->where('source_event_id', $sourceEventId)
                ->lockForUpdate()
                ->first();
            if ($existing instanceof ProcurementProcessEvent) {
                if (! hash_equals((string) $existing->source_hash, $attributes['source_hash'])) {
                    throw new DomainException('Procurement process event idempotency conflict.');
                }

                return $existing;
            }

            $latest = ProcurementProcessEvent::query()
                ->where('organization_id', $organizationId)
                ->where('purchase_request_line_id', $purchaseRequestLineId)
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();
            if ($latest instanceof ProcurementProcessEvent
                && $latest->occurred_at->getTimestamp() > $occurredAt->getTimestamp()) {
                throw new DomainException('Procurement process events must be monotonic.');
            }

            return ProcurementProcessEvent::query()->create($attributes);
        }, 3);
    }

    private function canonical(array $attributes): array
    {
        $attributes['occurred_at'] = $attributes['occurred_at']->format(DATE_ATOM);
        ksort($attributes, SORT_STRING);

        return $attributes;
    }
}
