<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\Services;

use App\BusinessModules\Features\Procurement\Models\PurchaseOrderItem;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Contracts\ProcurementCycleSourceState;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementCyclePolicyDefinition;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementProcessDimensionSnapshot;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Enums\ProcurementProcessEventCode;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Enums\ProcurementTerminalReason;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Models\ProcurementCyclePolicyVersion;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Models\ProcurementProcessEvent;
use DateTimeImmutable;
use DateTimeInterface;
use LogicException;

final class EloquentProcurementCycleSourceState implements ProcurementCycleSourceState
{
    public function activePolicy(
        int $organizationId,
        ?int $projectId,
        DateTimeImmutable $occurredAt,
    ): ?ProcurementCyclePolicyVersion {
        $query = ProcurementCyclePolicyVersion::query()
            ->where('organization_id', $organizationId)
            ->where('effective_from', '<=', $occurredAt)
            ->where(function ($builder) use ($occurredAt): void {
                $builder->whereNull('effective_to')->orWhere('effective_to', '>', $occurredAt);
            });
        if ($projectId === null) {
            $query->whereNull('project_id');
        } else {
            $query->where(function ($builder) use ($projectId): void {
                $builder->where('project_id', $projectId)->orWhereNull('project_id');
            })->orderByRaw('CASE WHEN project_id = ? THEN 0 ELSE 1 END', [$projectId]);
        }
        $policy = $query->orderByDesc('effective_from')->orderByDesc('version_number')->first();
        if (! $policy instanceof ProcurementCyclePolicyVersion) {
            return null;
        }

        $definition = new ProcurementCyclePolicyDefinition(
            organizationId: (int) $policy->organization_id,
            projectId: $this->positive($policy->project_id),
            timezone: (string) $policy->timezone,
            weeklyWindows: (array) $policy->weekly_windows,
            exceptions: (array) $policy->exceptions,
            stageSlaSeconds: (array) $policy->stage_sla_seconds,
            totalSlaSeconds: (int) $policy->total_sla_seconds,
            terminalCancellationPolicy: (array) $policy->terminal_cancellation_policy,
            effectiveFrom: $this->dateTime($policy->effective_from),
            effectiveTo: $policy->effective_to === null ? null : $this->dateTime($policy->effective_to),
            formulaVersion: (string) $policy->formula_version,
            sourceSchemaVersion: (string) $policy->source_schema_version,
            eventSchemaVersion: (string) $policy->event_schema_version,
            calendarVersion: (string) $policy->calendar_version,
        );
        if (! hash_equals((string) $policy->canonical_hash, $definition->canonicalHash())
            || ! hash_equals((string) $policy->calendar_hash, $definition->calendarHash())) {
            throw new LogicException('procurement_cycle_policy_integrity_mismatch');
        }

        return $policy;
    }

    public function requestCreatedSnapshot(
        int $organizationId,
        int $purchaseRequestLineId,
    ): ?ProcurementProcessDimensionSnapshot {
        $event = ProcurementProcessEvent::query()
            ->where('organization_id', $organizationId)
            ->where('purchase_request_line_id', $purchaseRequestLineId)
            ->where('event_code', ProcurementProcessEventCode::REQUEST_CREATED->value)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->first();

        return $event instanceof ProcurementProcessEvent && is_array($event->dimension_snapshot)
            ? ProcurementProcessDimensionSnapshot::fromArray($event->dimension_snapshot)
            : null;
    }

    public function policyAllows(
        ProcurementProcessDimensionSnapshot $snapshot,
        ProcurementTerminalReason $reason,
    ): bool {
        $policyId = $this->positive($snapshot->values['policy_version_id'] ?? null);
        if ($policyId === null) {
            return false;
        }
        $policy = ProcurementCyclePolicyVersion::query()->find($policyId);
        if (! $policy instanceof ProcurementCyclePolicyVersion
            || ! hash_equals((string) $policy->canonical_hash, (string) $snapshot->values['policy_hash'])
            || ! hash_equals((string) $policy->calendar_hash, (string) $snapshot->values['calendar_hash'])) {
            throw new LogicException('procurement_cycle_policy_integrity_mismatch');
        }

        return in_array($reason->value, (array) $policy->terminal_cancellation_policy, true);
    }

    public function eventExists(
        int $organizationId,
        int $purchaseRequestLineId,
        ProcurementProcessEventCode $eventCode,
    ): bool {
        return ProcurementProcessEvent::query()
            ->where('organization_id', $organizationId)
            ->where('purchase_request_line_id', $purchaseRequestLineId)
            ->where('event_code', $eventCode->value)
            ->exists();
    }

    public function isFullyReceived(int $purchaseOrderId, int $purchaseRequestLineId): bool
    {
        $ordered = PurchaseOrderItem::query()
            ->where('purchase_order_id', $purchaseOrderId)
            ->where('purchase_request_line_id', $purchaseRequestLineId)
            ->sum('quantity');
        $received = PurchaseOrderItem::query()
            ->where('purchase_order_id', $purchaseOrderId)
            ->where('purchase_request_line_id', $purchaseRequestLineId)
            ->join('purchase_receipt_lines', 'purchase_receipt_lines.purchase_order_item_id', '=', 'purchase_order_items.id')
            ->sum('purchase_receipt_lines.quantity_received');

        return $this->decimalUnits((string) $received, 3) >= $this->decimalUnits((string) $ordered, 3);
    }

    private function decimalUnits(string $value, int $scale): int
    {
        if (preg_match('/^(\d+)(?:\.(\d+))?$/D', $value, $matches) !== 1) {
            throw new LogicException('procurement_decimal_source_invalid');
        }
        $fraction = substr(str_pad($matches[2] ?? '', $scale, '0'), 0, $scale);

        return ((int) $matches[1] * (10 ** $scale)) + (int) $fraction;
    }

    private function positive(mixed $value): ?int
    {
        $value = is_numeric($value) ? (int) $value : 0;

        return $value > 0 ? $value : null;
    }

    private function dateTime(mixed $value): DateTimeImmutable
    {
        return $value instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($value)
            : new DateTimeImmutable((string) $value);
    }
}
