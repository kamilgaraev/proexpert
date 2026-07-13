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
        return $this->verifyScope(
            $context->organizationId,
            $context->projectId,
            $context->sessionId,
            (string) $context->baseInputVersion,
            $workItem,
        );
    }

    /** @param array<string, mixed> $workItem */
    public function verifyScope(
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $sourceVersion,
        array $workItem,
    ): bool {
        $id = $workItem['quantity_evidence_id'] ?? null;
        $fingerprint = $workItem['quantity_evidence_fingerprint'] ?? null;
        if (! is_int($id) || $id < 1 || ! is_string($fingerprint) || preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
            return false;
        }
        $node = $this->evidence->node($organizationId, $projectId, $sessionId, $id);
        if ($node === null || $node->type !== EvidenceType::WorkItem || $node->invalidatedAt !== null
            || ! hash_equals($node->fingerprint, $fingerprint)
            || ! hash_equals($node->sourceVersion, $sourceVersion)
            || ($node->value['unit'] ?? null) !== ($workItem['unit'] ?? null)) {
            return false;
        }

        try {
            return BigDecimal::of((string) ($node->value['quantity'] ?? ''))->compareTo(
                BigDecimal::of((string) ($workItem['quantity'] ?? '')),
            ) === 0;
        } catch (Throwable) {
            return false;
        }
    }
}
