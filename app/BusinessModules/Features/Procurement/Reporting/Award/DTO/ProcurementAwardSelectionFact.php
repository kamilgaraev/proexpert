<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Award\DTO;

use App\BusinessModules\Features\Procurement\Reporting\Award\Support\ProcurementAwardCanonicalizer;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ProcurementAwardSelectionFact
{
    private function __construct(
        public int $organizationId,
        public ?int $projectId,
        public int $purchaseRequestId,
        public int $supplierRequestId,
        public ?int $supplierRequestVersionId,
        public ?string $supplierRequestVersionHash,
        public int $decisionId,
        public string $selectedStatus,
        public DateTimeImmutable $occurredAt,
        public ?int $actorId,
        public ProcurementAwardManifest $manifest,
        public ProcurementAwardPolicyDefinition $policy,
        public bool $reasonPresent,
        public int $reasonNormalizedLength,
        public ?string $reasonDigest,
    ) {
        if ($organizationId < 1
            || ($projectId !== null && $projectId < 1)
            || $purchaseRequestId < 1
            || $supplierRequestId < 1
            || ($supplierRequestVersionId !== null && $supplierRequestVersionId < 1)
            || $decisionId < 1
            || ($supplierRequestVersionHash !== null
                && preg_match('/^[a-f0-9]{64}$/D', $supplierRequestVersionHash) !== 1)
            || ! in_array($selectedStatus, ['selected', 'approval_required'], true)
            || ($actorId !== null && $actorId < 1)) {
            throw new InvalidArgumentException('procurement_award_selection_fact_invalid');
        }
    }

    public static function create(
        int $organizationId,
        ?int $projectId,
        int $purchaseRequestId,
        int $supplierRequestId,
        ?int $supplierRequestVersionId,
        ?string $supplierRequestVersionHash,
        int $decisionId,
        string $selectedStatus,
        DateTimeImmutable $occurredAt,
        ?int $actorId,
        ProcurementAwardManifest $manifest,
        ProcurementAwardPolicyDefinition $policy,
        ?string $reason,
    ): self {
        $normalizedReason = $reason === null
            ? ''
            : trim((string) preg_replace('/\s+/u', ' ', $reason));

        return new self(
            organizationId: $organizationId,
            projectId: $projectId,
            purchaseRequestId: $purchaseRequestId,
            supplierRequestId: $supplierRequestId,
            supplierRequestVersionId: $supplierRequestVersionId,
            supplierRequestVersionHash: $supplierRequestVersionHash,
            decisionId: $decisionId,
            selectedStatus: $selectedStatus,
            occurredAt: $occurredAt,
            actorId: $actorId,
            manifest: $manifest,
            policy: $policy,
            reasonPresent: $normalizedReason !== '',
            reasonNormalizedLength: mb_strlen($normalizedReason),
            reasonDigest: $normalizedReason === '' ? null : hash('sha256', $normalizedReason),
        );
    }

    public function canonicalPayload(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'project_id' => $this->projectId,
            'purchase_request_id' => $this->purchaseRequestId,
            'supplier_request_id' => $this->supplierRequestId,
            'supplier_request_version_id' => $this->supplierRequestVersionId,
            'supplier_request_version_hash' => $this->supplierRequestVersionHash,
            'decision_id' => $this->decisionId,
            'selected_status' => $this->selectedStatus,
            'occurred_at' => $this->occurredAtUtc(),
            'actor_id' => $this->actorId,
            'manifest_hash' => $this->manifest->contentHash(),
            'policy_id' => $this->policy->policyId,
            'policy_version' => $this->policy->version,
            'policy_hash' => $this->policy->canonicalHash(),
            'reason_present' => $this->reasonPresent,
            'reason_normalized_length' => $this->reasonNormalizedLength,
            'reason_digest' => $this->reasonDigest,
        ];
    }

    public function fingerprint(): string
    {
        return ProcurementAwardCanonicalizer::hash($this->canonicalPayload());
    }

    public function occurredAtUtc(): string
    {
        return $this->occurredAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
