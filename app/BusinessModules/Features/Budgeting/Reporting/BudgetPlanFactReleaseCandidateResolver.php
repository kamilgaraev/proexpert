<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use InvalidArgumentException;

final class BudgetPlanFactReleaseCandidateResolver
{
    private const FILES = [
        BudgetPlanFactReleaseCandidateLayout::CANDIDATE_MANIFEST,
        BudgetPlanFactReleaseCandidateLayout::CONFORMANCE_EVIDENCE,
        BudgetPlanFactReleaseCandidateLayout::PROOF_TEMPLATE,
        BudgetPlanFactReleaseCandidateLayout::REQUEST_FILE,
    ];

    /** @return array<string, array<string, mixed>> */
    public function resolve(string $directory, string $commitSha): array
    {
        if (preg_match('/^[a-f0-9]{40}$/D', $commitSha) !== 1) {
            $this->reject();
        }
        $root = realpath($directory);
        if (! is_string($root) || is_link($directory) || ! is_dir($root)) {
            $this->reject();
        }

        $documents = [];
        foreach (self::FILES as $file) {
            $path = $root.DIRECTORY_SEPARATOR.$file;
            $bytes = ! is_link($path) && is_file($path) ? file_get_contents($path) : false;
            try {
                $document = is_string($bytes) ? json_decode($bytes, true, 64, JSON_THROW_ON_ERROR) : null;
            } catch (\JsonException) {
                $this->reject();
            }
            if (! is_array($document) || array_is_list($document) || ! hash_equals(CanonicalJson::encode($document), (string) $bytes)) {
                $this->reject();
            }
            $documents[$file] = $document;
        }

        $candidate = $documents[BudgetPlanFactReleaseCandidateLayout::CANDIDATE_MANIFEST];
        $conformance = $documents[BudgetPlanFactReleaseCandidateLayout::CONFORMANCE_EVIDENCE];
        $proof = $documents[BudgetPlanFactReleaseCandidateLayout::PROOF_TEMPLATE];
        $request = $documents[BudgetPlanFactReleaseCandidateLayout::REQUEST_FILE];
        $definition = $candidate['candidate_definition'] ?? null;
        $closeIdentity = $candidate['source_close_identity'] ?? null;

        if (! is_array($definition) || array_is_list($definition)
            || ! is_array($closeIdentity) || array_is_list($closeIdentity)
            || ! $this->validCloseIdentity($closeIdentity)
            || ! $this->sameKeys($candidate, [
                'candidate_definition', 'candidate_definition_sha256', 'code', 'formula_sha256', 'formula_version',
                'generated_from_commit', 'publication_status', 'source_close_identity', 'source_close_identity_sha256',
                'source_schema_version', 'source_sha256',
            ])
            || ! $this->sameKeys($conformance, [
                'code', 'commit_sha', 'definition_sha256', 'formula_sha256', 'source_close_identity_sha256', 'source_sha256', 'status',
            ])
            || ! $this->sameKeys($proof, [
                'candidate_definition_sha256', 'candidate_manifest_sha256', 'code', 'conformance_evidence_sha256',
                'formula_sha256', 'release_commit_sha', 'source_close_identity_sha256', 'source_sha256',
            ])
            || $candidate['code'] !== BudgetPlanFactCandidateContract::CODE
            || $candidate['publication_status'] !== 'candidate'
            || $candidate['generated_from_commit'] !== $commitSha
            || $candidate['formula_version'] !== BudgetPlanFactCandidateContract::FORMULA_VERSION
            || $candidate['formula_sha256'] !== BudgetPlanFactCandidateContract::FORMULA_HASH
            || $candidate['source_sha256'] !== BudgetPlanFactCandidateContract::SOURCE_HASH
            || $candidate['source_schema_version'] !== (new BudgetPlanFactCandidateContract)->sourceSchemaVersion
            || ! hash_equals(hash('sha256', CanonicalJson::encode($definition)), (string) $candidate['candidate_definition_sha256'])
            || ! hash_equals(hash('sha256', CanonicalJson::encode($closeIdentity)), (string) $candidate['source_close_identity_sha256'])
            || ! $this->matchesContract($definition)
            || $conformance['code'] !== BudgetPlanFactCandidateContract::CODE
            || $conformance['commit_sha'] !== $commitSha
            || $conformance['status'] !== 'passed'
            || ! $this->identicalHashes($candidate, $conformance, ['candidate_definition_sha256' => 'definition_sha256', 'formula_sha256' => 'formula_sha256', 'source_close_identity_sha256' => 'source_close_identity_sha256', 'source_sha256' => 'source_sha256'])
            || $proof['code'] !== BudgetPlanFactCandidateContract::CODE
            || $proof['release_commit_sha'] !== $commitSha
            || ! $this->identicalHashes($candidate, $proof, ['candidate_definition_sha256' => 'candidate_definition_sha256', 'formula_sha256' => 'formula_sha256', 'source_close_identity_sha256' => 'source_close_identity_sha256', 'source_sha256' => 'source_sha256'])
            || ! hash_equals(hash('sha256', CanonicalJson::encode($candidate)), (string) $proof['candidate_manifest_sha256'])
            || ! hash_equals(hash('sha256', CanonicalJson::encode($conformance)), (string) $proof['conformance_evidence_sha256'])
            || ! hash_equals(
                CanonicalJson::encode(BudgetPlanFactReleaseCandidateLayout::request($commitSha, hash('sha256', CanonicalJson::encode($proof)))),
                CanonicalJson::encode($request),
            )) {
            $this->reject();
        }

        return $documents;
    }

    /** @param array<string, mixed> $definition */
    private function matchesContract(array $definition): bool
    {
        try {
            $contract = new BudgetPlanFactCandidateContract;
            $contract->assertRuntimeMatches();
            $contract->assertCandidateManifestDefinition($definition);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $left @param array<string, mixed> $right @param array<string, string> $mapping */
    private function identicalHashes(array $left, array $right, array $mapping): bool
    {
        foreach ($mapping as $leftKey => $rightKey) {
            if (! is_string($left[$leftKey] ?? null) || ! is_string($right[$rightKey] ?? null)
                || ! hash_equals($left[$leftKey], $right[$rightKey])) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $document @param list<string> $expected */
    private function sameKeys(array $document, array $expected): bool
    {
        $actual = array_keys($document);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);

        return $actual === $expected;
    }

    /** @param array<string, mixed> $identity */
    private function validCloseIdentity(array $identity): bool
    {
        if (! $this->sameKeys($identity, [
            'budget_version_uuid',
            'organization_id',
            'period_end',
            'period_start',
            'scenario_uuid',
        ])) {
            return false;
        }

        return is_int($identity['organization_id'])
            && $identity['organization_id'] > 0
            && is_string($identity['period_start'])
            && is_string($identity['period_end'])
            && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/D', $identity['period_start']) === 1
            && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/D', $identity['period_end']) === 1
            && $identity['period_start'] <= $identity['period_end']
            && is_string($identity['scenario_uuid'])
            && $identity['scenario_uuid'] !== ''
            && is_string($identity['budget_version_uuid'])
            && $identity['budget_version_uuid'] !== '';
    }

    private function reject(): never
    {
        throw new InvalidArgumentException('budget_plan_fact_release_candidate_untrusted');
    }
}
