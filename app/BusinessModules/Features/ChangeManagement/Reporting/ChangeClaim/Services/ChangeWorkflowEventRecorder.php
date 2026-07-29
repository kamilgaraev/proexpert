<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Services;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\ChangeManagement\Models\ChangeClaim;
use App\BusinessModules\Features\ChangeManagement\Models\ChangeRequest;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Models\ChangeClaimLink;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Models\ChangeRequestVersion;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Models\ChangeWorkflowEvent;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class ChangeWorkflowEventRecorder
{
    public function record(
        ChangeRequest $change,
        string $eventType,
        CarbonImmutable $occurredAt,
        ?int $actorId,
    ): ChangeWorkflowEvent {
        return DB::transaction(function () use ($change, $eventType, $occurredAt, $actorId): ChangeWorkflowEvent {
            $change->loadMissing('impact');
            $latest = ChangeRequestVersion::query()
                ->where('organization_id', $change->organization_id)
                ->where('change_request_id', $change->id)
                ->lockForUpdate()
                ->latest('version')
                ->first();
            $version = ($latest?->version ?? 0) + 1;
            $links = is_array($change->linked_entities) ? $change->linked_entities : [];
            $currency = $this->currency($links['currency'] ?? null);
            $proposed = $this->minor($change->impact?->cost_delta ?? 0);
            $approved = in_array($eventType, ['approve', 'implement', 'close'], true)
                && $change->approved_at !== null
                && array_key_exists('approved_cost', $links)
                ? $this->minor($links['approved_cost'])
                : null;
            $payload = [
                'organization_id' => (int) $change->organization_id,
                'change_request_id' => (int) $change->id,
                'version' => $version,
                'project_id' => (int) $change->project_id,
                'contract_id' => $this->positiveInt($links['contract_id'] ?? null),
                'contract_project_allocation_id' => $this->positiveInt($links['contract_project_allocation_id'] ?? null),
                'initiator_user_id' => $change->created_by_user_id,
                'initiator_type' => (string) $change->initiator_type,
                'reason' => (string) $change->reason,
                'owner_user_id' => $this->positiveInt($links['owner_user_id'] ?? null),
                'status' => (string) $change->status,
                'proposed_cost_minor' => $proposed,
                'proposed_schedule_days' => (int) ($change->impact?->schedule_delta_days ?? 0),
                'approved_cost_minor' => $approved,
                'approved_schedule_days' => $approved === null || !array_key_exists('approved_schedule_days', $links)
                    ? null
                    : (int) $links['approved_schedule_days'],
                'currency' => $currency,
                'currency_source' => $currency === null ? null : (string) ($links['currency_source'] ?? 'change_request'),
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

            return ChangeWorkflowEvent::query()->create([
                ...$eventPayload,
                'event_hash' => hash('sha256', CanonicalJson::encode($eventPayload)),
            ]);
        });
    }

    public function linkClaim(ChangeRequest $change, ChangeClaim $claim, int $claimVersion = 1): ChangeClaimLink
    {
        $version = ChangeRequestVersion::query()
            ->where('organization_id', $change->organization_id)
            ->where('change_request_id', $change->id)
            ->latest('version')
            ->first();
        if (!$version instanceof ChangeRequestVersion || $version->currency === null) {
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

        return ChangeClaimLink::query()->create([
            ...$payload,
            'source_hash' => hash('sha256', CanonicalJson::encode($payload)),
        ]);
    }

    private function minor(mixed $amount): int
    {
        if (!is_numeric($amount)) {
            throw new DomainException('change_claim_money_invalid');
        }
        $normalized = number_format((float) $amount, 2, '.', '');
        if (abs((float) $amount - (float) $normalized) > 0.000001) {
            throw new DomainException('change_claim_money_minor_unit_loss');
        }
        $negative = str_starts_with($normalized, '-');
        [$whole, $fraction] = explode('.', ltrim($normalized, '-'));
        $minor = (int) $whole * 100 + (int) $fraction;

        return $negative ? -$minor : $minor;
    }

    private function currency(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('/^[A-Z]{3}$/', mb_strtoupper($value)) !== 1) {
            return null;
        }

        return mb_strtoupper($value);
    }

    private function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
