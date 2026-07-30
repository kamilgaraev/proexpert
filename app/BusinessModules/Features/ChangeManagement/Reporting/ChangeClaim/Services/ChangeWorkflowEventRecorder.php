<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Services;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Core\Reporting\Support\ExactDecimal;
use App\BusinessModules\Features\ChangeManagement\Models\ChangeClaim;
use App\BusinessModules\Features\ChangeManagement\Models\ChangeRequest;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\DTO\ContingencyMovement;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Models\ChangeClaimLink;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Models\ChangeRequestVersion;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Models\ChangeWorkflowEvent;
use App\Models\ContractProjectAllocation;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class ChangeWorkflowEventRecorder
{
    public function __construct(private ContingencyLedgerService $contingencyLedger) {}

    public function record(
        ChangeRequest $change,
        string $eventType,
        CarbonImmutable $occurredAt,
        ?int $actorId,
    ): ChangeWorkflowEvent {
        return DB::transaction(function () use ($change, $eventType, $occurredAt, $actorId): ChangeWorkflowEvent {
            DB::table('change_requests')
                ->where('organization_id', $change->organization_id)
                ->where('id', $change->id)
                ->lockForUpdate()
                ->first();
            $change->loadMissing('impact');
            $latest = ChangeRequestVersion::query()
                ->where('organization_id', $change->organization_id)
                ->where('change_request_id', $change->id)
                ->lockForUpdate()
                ->latest('version')
                ->first();
            $version = ($latest?->version ?? 0) + 1;
            $links = is_array($change->linked_entities) ? $change->linked_entities : [];
            $currency = $this->currency($change->reporting_currency);
            $proposed = $this->minor($change->impact?->cost_delta ?? 0);
            $approval = $change->approvals()
                ->where('status', 'approved')
                ->whereNotNull('approved_cost_minor')
                ->latest('decided_at')
                ->first();
            $approved = $approval?->approved_cost_minor;
            if (in_array($eventType, ['approve', 'implement', 'close'], true)
                && (! is_int($approved) || $approval?->currency !== $currency)) {
                throw new DomainException('change_approved_monetary_evidence_missing');
            }
            $allocationId = $change->reporting_contract_project_allocation_id;
            $contractId = $allocationId === null
                ? null
                : ContractProjectAllocation::query()->whereKey((int) $allocationId)->value('contract_id');
            $payload = [
                'organization_id' => (int) $change->organization_id,
                'change_request_id' => (int) $change->id,
                'version' => $version,
                'project_id' => (int) $change->project_id,
                'contract_id' => $this->positiveInt($contractId),
                'contract_project_allocation_id' => $this->positiveInt($allocationId),
                'initiator_user_id' => $change->created_by_user_id,
                'initiator_type' => (string) $change->initiator_type,
                'reason' => (string) $change->reason,
                'owner_user_id' => $this->positiveInt($links['owner_user_id'] ?? null),
                'status' => (string) $change->status,
                'proposed_cost_minor' => $proposed,
                'proposed_schedule_days' => (int) ($change->impact?->schedule_delta_days ?? 0),
                'approved_cost_minor' => $approved,
                'approved_schedule_days' => $approved === null || ! array_key_exists('approved_schedule_days', $links)
                    ? null
                    : (int) $links['approved_schedule_days'],
                'currency' => $currency,
                'currency_source' => $currency === null ? null : 'change_request_monetary_context',
                'effective_at' => $occurredAt->format(DATE_ATOM),
            ];
            $versionRecord = ChangeRequestVersion::query()->create([
                ...$payload,
                'source_hash' => hash('sha256', CanonicalJson::encode($payload)),
            ]);
            $priorStatus = $latest?->status;
            $eventPayload = [
                'organization_id' => (int) $change->organization_id,
                'change_request_id' => (int) $change->id,
                'version' => (int) $versionRecord->version,
                'project_id' => (int) $change->project_id,
                'event_type' => $eventType,
                'prior_status' => $priorStatus,
                'current_status' => (string) $change->status,
                'actor_id' => $actorId,
                'occurred_at' => $occurredAt->format(DATE_ATOM),
            ];

            $event = ChangeWorkflowEvent::query()->create([
                ...$eventPayload,
                'event_hash' => hash('sha256', CanonicalJson::encode($eventPayload)),
            ]);
            $this->recordContingency($change, $versionRecord, $eventType, $occurredAt);

            return $event;
        });
    }

    public function linkClaim(ChangeRequest $change, ChangeClaim $claim, int $claimVersion = 1): ChangeClaimLink
    {
        $version = ChangeRequestVersion::query()
            ->where('organization_id', $change->organization_id)
            ->where('change_request_id', $change->id)
            ->latest('version')
            ->first();
        if (! $version instanceof ChangeRequestVersion || $version->currency === null) {
            throw new DomainException('change_claim_version_not_ready');
        }
        $amountMinor = $this->minor($claim->amount);
        $payload = [
            'organization_id' => (int) $change->organization_id,
            'change_request_version_id' => (int) $version->id,
            'change_claim_id' => (int) $claim->id,
            'claim_version' => $claimVersion,
            'claim_amount_minor' => $amountMinor,
            'currency' => (string) $version->currency,
            'relationship_type' => 'claim',
        ];

        $sourceHash = hash('sha256', CanonicalJson::encode($payload));
        ChangeClaimLink::query()->insertOrIgnore([[
            ...$payload,
            'source_hash' => $sourceHash,
            'created_at' => now(),
            'updated_at' => now(),
        ]]);
        $link = ChangeClaimLink::query()
            ->where('organization_id', $change->organization_id)
            ->where('change_claim_id', $claim->id)
            ->where('claim_version', $claimVersion)
            ->first();
        if (! $link instanceof ChangeClaimLink
            || ! hash_equals((string) $link->source_hash, $sourceHash)) {
            throw new DomainException('change_claim_link_replay_conflict');
        }

        return $link;
    }

    private function minor(mixed $amount): int
    {
        if (! is_int($amount) && ! is_float($amount) && ! is_string($amount)) {
            throw new DomainException('change_claim_money_invalid');
        }

        return ExactDecimal::minor((string) $amount);
    }

    private function currency(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^[A-Z]{3}$/', mb_strtoupper($value)) !== 1) {
            return null;
        }

        return mb_strtoupper($value);
    }

    private function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function recordContingency(
        ChangeRequest $change,
        ChangeRequestVersion $version,
        string $eventType,
        CarbonImmutable $occurredAt,
    ): void {
        if ($version->contract_project_allocation_id === null || $version->currency === null) {
            return;
        }
        $amount = match ($eventType) {
            'create' => $change->contingency_opening_minor,
            'submit' => $change->contingency_allocation_minor,
            'approve' => $version->approved_cost_minor,
            'close' => $change->contingency_release_minor,
            default => null,
        };
        if ($amount === null) {
            return;
        }
        if (! is_int($amount)) {
            throw new DomainException('change_contingency_monetary_evidence_invalid');
        }
        $amountMinor = $amount;
        $type = match ($eventType) {
            'create' => 'opening',
            'submit' => 'allocation',
            'approve' => 'consumption',
            'close' => 'release',
        };
        $this->contingencyLedger->append(
            $change,
            ContingencyMovement::recorded(
                type: $type,
                amountMinor: $amountMinor,
                currency: (string) $version->currency,
                projectId: (int) $version->project_id,
                allocationId: (int) $version->contract_project_allocation_id,
                sourceType: 'change_request',
                sourceId: (string) $change->id,
                sourceVersion: (int) $version->version,
                idempotencyKey: implode(':', ['change', $change->id, $version->version, $type]),
            ),
            $occurredAt,
        );
    }
}
