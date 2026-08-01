<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ReportReleaseGateBundle
{
    public array $sectionHashes;

    public function __construct(
        public string $artifactId,
        public string $status,
        public string $releaseSha,
        public string $activationCommitSha,
        public string $adminEvidenceCommitSha,
        public array $gates,
        public array $sources,
        public DateTimeImmutable $generatedAt,
        public array $ownershipCounts = [],
    ) {
        if ($artifactId !== 'report_release_gate_bundle'
            || $status !== 'release_gates_passed'
            || preg_match('/^[a-f0-9]{40}$/D', $releaseSha) !== 1
            || preg_match('/^[a-f0-9]{40}$/D', $activationCommitSha) !== 1
            || preg_match('/^[a-f0-9]{40}$/D', $adminEvidenceCommitSha) !== 1
            || $activationCommitSha === $releaseSha
            || ! array_is_list($gates)
            || count($gates) !== 14) {
            throw new InvalidArgumentException('report_release_gate_bundle_invalid');
        }
        if ($ownershipCounts !== [] && $ownershipCounts !== ['backend' => 9, 'admin' => 4, 'joint' => 1]) {
            throw new InvalidArgumentException('report_release_gate_bundle_invalid');
        }

        $serializedGates = array_map(
            static fn (ReportQualityGateEvidence $gate): array => [
                'gate' => $gate->gate,
                'owner_plan' => $gate->ownerPlan,
                'phase' => $gate->phase->value,
                'status' => $gate->status->value,
                'command' => $gate->command,
                'count' => $gate->count,
                'schema_sha256' => $gate->schemaHash->value,
                'commit_sha' => $gate->commitSha,
                'executed_at' => $gate->executedAt->format('Y-m-d\TH:i:s\Z'),
                'artifact_sha256' => $gate->artifactHash?->value,
            ],
            $gates,
        );
        $this->sectionHashes = [
            'source_artifacts' => hash('sha256', CanonicalJson::encode($sources)),
            'gates' => hash('sha256', CanonicalJson::encode($serializedGates)),
        ];
    }
}
