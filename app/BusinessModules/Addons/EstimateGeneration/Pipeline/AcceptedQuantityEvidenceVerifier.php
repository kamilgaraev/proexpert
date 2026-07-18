<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceType;
use Brick\Math\BigDecimal;
use Throwable;

final readonly class AcceptedQuantityEvidenceVerifier
{
    public function __construct(private EvidenceRepository $evidence) {}

    /** @param array<string, mixed> $workItem */
    public function verify(PipelineContext $context, array $workItem): bool
    {
        return $this->rejectionReason(
            $context->organizationId,
            $context->projectId,
            $context->sessionId,
            (string) $context->baseInputVersion,
            $workItem,
        ) === null;
    }

    /** @param array<string, mixed> $workItem */
    public function verifyScope(
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $sourceVersion,
        array $workItem,
    ): bool {
        return $this->rejectionReason(
            $organizationId,
            $projectId,
            $sessionId,
            $sourceVersion,
            $workItem,
        ) === null;
    }

    /** @param array<string, mixed> $workItem */
    public function rejectionReason(
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $sourceVersion,
        array $workItem,
    ): ?string {
        $id = $this->positiveId($workItem['quantity_evidence_id'] ?? null);
        $fingerprint = $workItem['quantity_evidence_fingerprint'] ?? null;
        if ($id === null) {
            return 'id_invalid';
        }
        if (! is_string($fingerprint) || preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
            return 'fingerprint_invalid';
        }
        $node = $this->evidence->node($organizationId, $projectId, $sessionId, $id);
        if ($node === null) {
            return 'node_missing';
        }
        if ($node->type !== EvidenceType::WorkItem) {
            return 'type_mismatch';
        }
        if ($node->invalidatedAt !== null) {
            return 'node_invalidated';
        }
        if (! hash_equals($node->fingerprint, $fingerprint)) {
            return 'fingerprint_mismatch';
        }
        if (! hash_equals($node->sourceVersion, $sourceVersion)) {
            return 'source_version_mismatch';
        }
        if (($node->value['unit'] ?? null) !== ($workItem['unit'] ?? null)) {
            return 'unit_mismatch';
        }

        try {
            return BigDecimal::of((string) ($node->value['quantity'] ?? ''))->compareTo(
                BigDecimal::of((string) ($workItem['quantity'] ?? '')),
            ) === 0 ? null : 'quantity_mismatch';
        } catch (Throwable) {
            return 'quantity_invalid';
        }
    }

    private function positiveId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (! is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            return null;
        }

        $id = (int) $value;

        return $id > 0 && (string) $id === $value ? $id : null;
    }
}
