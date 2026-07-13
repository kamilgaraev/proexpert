<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

use JsonException;

final readonly class AcceptanceOwnerApprovalGuard
{
    public function __construct(private BenchmarkPrivateObjectStore $store) {}

    /** @param array<string, mixed> $manifest @return array<string, mixed> */
    public function approvedManifest(int $organizationId, array $manifest): array
    {
        $record = $manifest['owner_approval'] ?? null;
        if (! is_array($record) || array_keys($record) !== [
            'status', 'gate_execution_allowed', 'approval_locator', 'approval_sha256',
            'corpus_digest', 'provenance',
        ]) {
            throw new BenchmarkContractException('acceptance_owner_approval_missing');
        }
        if ($record['status'] !== 'approved' || $record['gate_execution_allowed'] !== true) {
            throw new BenchmarkContractException('acceptance_owner_approval_required');
        }
        foreach (['approval_sha256', 'corpus_digest'] as $hash) {
            if (! is_string($record[$hash]) || preg_match('/^[a-f0-9]{64}$/D', $record[$hash]) !== 1) {
                throw new BenchmarkContractException('acceptance_owner_approval_invalid');
            }
        }
        if (! is_string($record['provenance']) || ! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{2,127}$/D', $record['provenance'])) {
            throw new BenchmarkContractException('acceptance_owner_approval_invalid');
        }
        $locator = $record['approval_locator'] ?? null;
        if (! is_string($locator) || ! preg_match('#^s3://org-\{organization_id\}/estimate-generation/benchmarks/acceptance/[a-zA-Z0-9._/-]+\.json$#', $locator)
            || str_contains($locator, '..') || str_contains($locator, '?')) {
            throw new BenchmarkContractException('acceptance_owner_approval_invalid');
        }
        $approvalJson = $this->store->read(substr(str_replace('{organization_id}', (string) $organizationId, $locator), 5), 32_000);
        if (! hash_equals($record['approval_sha256'], hash('sha256', $approvalJson))) {
            throw new BenchmarkContractException('acceptance_owner_approval_digest_mismatch');
        }
        try {
            $approval = json_decode($approvalJson, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new BenchmarkContractException('acceptance_owner_approval_invalid');
        }
        if (! is_array($approval) || array_keys($approval) !== [
            'schema_version', 'status', 'approved', 'gate_execution_allowed', 'corpus_digest', 'provenance',
        ] || $approval['schema_version'] !== 1 || $approval['status'] !== 'approved'
            || $approval['approved'] !== true || $approval['gate_execution_allowed'] !== true
            || $approval['provenance'] !== $record['provenance']
            || ! is_string($approval['corpus_digest'])
            || ! hash_equals($record['corpus_digest'], $approval['corpus_digest'])) {
            throw new BenchmarkContractException('acceptance_owner_approval_invalid');
        }
        $approvedManifest = $manifest;
        unset($approvedManifest['owner_approval']);
        if (! hash_equals($record['corpus_digest'], $this->corpusDigest($approvedManifest))) {
            throw new BenchmarkContractException('acceptance_owner_approval_corpus_mismatch');
        }

        return $approvedManifest;
    }

    /** @param array<string, mixed> $manifest */
    private function corpusDigest(array $manifest): string
    {
        $identity = [
            'schema_version' => $manifest['schema_version'] ?? null,
            'manifest_version' => $manifest['manifest_version'] ?? null,
            'cases' => $manifest['cases'] ?? null,
        ];
        $this->sort($identity);

        return hash('sha256', json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function sort(array &$value): void
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                $this->sort($item);
            }
        }
    }
}
