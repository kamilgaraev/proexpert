<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository;
use InvalidArgumentException;

final readonly class EstimateAuditInputFactory
{
    public function __construct(
        private ProjectModelRepository $models,
        private EvidenceRepository $evidence,
        private int $maxFacts,
    ) {
        if ($maxFacts < 1 || $maxFacts > 10000) {
            throw new InvalidArgumentException('estimate_audit_fact_limit_invalid');
        }
    }

    public function capture(int $organizationId, int $projectId, int $sessionId, array $draft): EstimateAuditInput
    {
        $capture = $this->models->snapshotForPlanning($organizationId, $projectId, $sessionId, $this->maxFacts + 1);
        $snapshot = $capture['snapshot'];
        if (count($snapshot->facts) > $this->maxFacts) {
            throw new InvalidArgumentException('estimate_audit_fact_limit_exceeded');
        }
        $verified = $this->models->snapshotForPlanning($organizationId, $projectId, $sessionId, $this->maxFacts + 1);
        if (! hash_equals((string) $capture['token'], (string) $verified['token'])) {
            throw new InvalidArgumentException('estimate_audit_snapshot_changed');
        }
        $facts = array_map(static fn (Fact $fact): array => [
            'id' => $fact->id,
            'source_version' => $fact->sourceVersion,
            'entity_id' => $fact->entityId,
            'type' => $fact->type,
            'value' => $fact->value,
            'unit' => $fact->unit,
            'origin' => $fact->origin,
            'status' => $fact->status,
            'evidence_ids' => $fact->evidenceIds,
            'version' => $fact->version,
        ], $snapshot->facts);
        $factSet = array_fill_keys(array_column($facts, 'id'), true);

        return new EstimateAuditInput(
            $organizationId,
            $projectId,
            $sessionId,
            (string) $capture['token'],
            0,
            $facts,
            is_array($draft['estimate_composition']['derived_quantities'] ?? null)
                ? array_values(array_filter($draft['estimate_composition']['derived_quantities'], 'is_array'))
                : [],
            $draft,
            $this->evidence(
                $organizationId,
                $projectId,
                $sessionId,
                $draft,
                $snapshot->facts,
                $snapshot->evidence,
                $factSet,
            ),
            RunEstimateAudit::PROMPT_CONTRACT,
        );
    }

    /** @param array<string,bool> $factSet @return list<array{fact_id:string,locator:array<string,mixed>}> */
    private function evidence(
        int $organizationId,
        int $projectId,
        int $sessionId,
        array $draft,
        array $facts,
        array $projectEvidence,
        array $factSet,
    ): array {
        $records = [];
        $projectEvidenceById = [];
        foreach ($projectEvidence as $item) {
            $projectEvidenceById[$item->id] = $item;
        }
        foreach ($facts as $fact) {
            foreach ($fact->evidenceIds as $evidenceId) {
                $projectItem = $projectEvidenceById[$evidenceId] ?? null;
                if ($projectItem !== null) {
                    $locator = array_filter([
                        'evidence_id' => $evidenceId,
                        'source_artifact_id' => $projectItem->sourceArtifactId,
                        'source_type' => $projectItem->sourceType,
                        'source_version' => $projectItem->sourceVersion,
                        'page' => $projectItem->page,
                        'region' => $projectItem->region,
                        'native_reference' => $projectItem->nativeReference,
                    ], static fn (mixed $value): bool => $value !== null);
                } else {
                    if (preg_match('/^evidence:(?<id>[1-9][0-9]*)$/D', $evidenceId, $matches) !== 1) {
                        throw new InvalidArgumentException('estimate_audit_evidence_identity_invalid');
                    }
                    $node = $this->evidence->node($organizationId, $projectId, $sessionId, (int) $matches['id']);
                    if ($node === null || $node->invalidatedAt !== null) {
                        throw new InvalidArgumentException('estimate_audit_evidence_not_current');
                    }
                    $locator = [
                        'evidence_id' => $evidenceId,
                        'source_type' => $node->sourceType->value,
                        'source_ref' => $node->sourceRef,
                        'source_version' => $node->sourceVersion,
                        'locator' => $node->locator,
                    ];
                }
                $key = $fact->id.'|'.hash('sha256', json_encode($locator, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $records[$key] = ['fact_id' => $fact->id, 'locator' => $locator];
                if (count($records) >= 20000) {
                    return array_values($records);
                }
            }
        }
        foreach ($draft['local_estimates'] ?? [] as $localEstimate) {
            foreach (is_array($localEstimate) ? ($localEstimate['sections'] ?? []) : [] as $section) {
                foreach (is_array($section) ? ($section['work_items'] ?? []) : [] as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $refs = is_array($item['source_refs'] ?? null) ? $item['source_refs'] : [];
                    $provenanceRefs = $item['metadata']['stage6_provenance']['source_refs'] ?? [];
                    if (is_array($provenanceRefs)) {
                        array_push($refs, ...$provenanceRefs);
                    }
                    foreach ($refs as $ref) {
                        $factId = is_array($ref) ? ($ref['fact_id'] ?? $ref['id'] ?? null) : null;
                        if (! is_string($factId) || ! isset($factSet[$factId])) {
                            continue;
                        }
                        $locator = $ref;
                        unset($locator['fact_id'], $locator['id']);
                        if ($locator === []) {
                            $locator = ['work_item_key' => $item['key'] ?? null];
                        }
                        $key = $factId.'|'.hash('sha256', json_encode($locator, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                        $records[$key] = ['fact_id' => $factId, 'locator' => $locator];
                        if (count($records) >= 20000) {
                            return array_values($records);
                        }
                    }
                }
            }
        }

        return array_values($records);
    }
}
