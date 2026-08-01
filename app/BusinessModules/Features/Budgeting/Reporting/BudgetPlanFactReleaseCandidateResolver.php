<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionConformanceEvidence;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFormulaConformanceEvidence;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationProof;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceConformanceEvidence;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
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

        try {
            $publicationProof = ReportPublicationProof::fromArray($proof);
            $conformanceEvidence = $this->conformanceEvidence($conformance);
        } catch (\Throwable) {
            $this->reject();
        }

        if (! is_array($definition) || array_is_list($definition)
            || ! is_array($closeIdentity) || array_is_list($closeIdentity)
            || ! $this->validCloseIdentity($closeIdentity)
            || ! $this->sameKeys($candidate, [
                'candidate_definition', 'candidate_definition_sha256', 'code', 'formula_sha256', 'formula_version',
                'generated_from_commit', 'publication_status', 'source_close_identity', 'source_close_identity_sha256',
                'source_close_id', 'source_schema_version', 'source_sha256',
            ])
            || ! $this->sameKeys($conformance, [
                'assertion_count', 'code', 'commit_sha', 'component_class_hashes', 'contract_version', 'definition_hash', 'digest',
                'fixture_hash', 'formula', 'generated_at', 'source', 'source_schema_version', 'status',
            ])
            || $candidate['code'] !== BudgetPlanFactCandidateContract::CODE
            || $candidate['publication_status'] !== 'candidate'
            || $candidate['generated_from_commit'] !== $commitSha
            || $candidate['formula_version'] !== BudgetPlanFactCandidateContract::FORMULA_VERSION
            || $candidate['formula_sha256'] !== BudgetPlanFactCandidateContract::FORMULA_HASH
            || $candidate['source_sha256'] !== BudgetPlanFactCandidateContract::SOURCE_HASH
            || $candidate['source_schema_version'] !== (new BudgetPlanFactCandidateContract)->sourceSchemaVersion
            || ! is_string($candidate['source_close_id'])
            || preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D', $candidate['source_close_id']) !== 1
            || ! hash_equals(hash('sha256', CanonicalJson::encode($definition)), (string) $candidate['candidate_definition_sha256'])
            || ! hash_equals(hash('sha256', CanonicalJson::encode($closeIdentity)), (string) $candidate['source_close_identity_sha256'])
            || ! $this->matchesContract($definition)
            || $conformance['code'] !== BudgetPlanFactCandidateContract::CODE
            || $conformance['commit_sha'] !== $commitSha
            || $conformance['status'] !== 'passed'
            || ! isset($conformance['digest'])
            || ! is_string($conformance['digest'])
            || ! hash_equals($conformanceEvidence->digest()->value, $conformance['digest'])
            || ($conformance['definition_hash'] ?? null) !== $candidate['candidate_definition_sha256']
            || ($conformance['source_schema_version'] ?? null) !== $candidate['source_schema_version']
            || ($conformance['formula']['formula_version'] ?? null) !== BudgetPlanFactCandidateContract::FORMULA_VERSION
            || ($conformance['source']['source_hash'] ?? null) !== BudgetPlanFactCandidateContract::SOURCE_HASH
            || ($conformance['source']['snapshot_id'] ?? null) !== $candidate['source_close_id']
            || $publicationProof->payload()['code'] !== BudgetPlanFactCandidateContract::CODE
            || $publicationProof->payload()['release']['git_sha'] !== $commitSha
            || $publicationProof->payload()['candidate_definition_sha256'] !== $candidate['candidate_definition_sha256']
            || ! hash_equals(hash('sha256', CanonicalJson::encode($candidate)), $publicationProof->payload()['candidate_manifest_sha256'])
            || $publicationProof->payload()['conformance_evidence_sha256'] !== $conformance['digest']
            || $publicationProof->payload()['formula']['formula_version'] !== BudgetPlanFactCandidateContract::FORMULA_VERSION
            || $publicationProof->payload()['source']['source_sha256'] !== BudgetPlanFactCandidateContract::SOURCE_HASH
            || ! $this->hasRuntimeComponentHashes($conformance)
            || ! hash_equals(
                CanonicalJson::encode(BudgetPlanFactReleaseCandidateLayout::request($commitSha, $publicationProof->digest()->value)),
                CanonicalJson::encode($request),
            )) {
            $this->reject();
        }

        return $documents;
    }

    /** @param array<string, mixed> $document */
    private function conformanceEvidence(array $document): ReportDefinitionConformanceEvidence
    {
        $components = $document['component_class_hashes'] ?? null;
        $source = $document['source'] ?? null;
        $formula = $document['formula'] ?? null;
        if (! is_array($components) || ! array_is_list($components)
            || ! is_array($source) || array_is_list($source)
            || ! is_array($formula) || array_is_list($formula)) {
            throw new InvalidArgumentException('budget_plan_fact_release_candidate_untrusted');
        }
        $hashes = [];
        foreach ($components as $component) {
            if (! is_array($component)
                || ! is_string($component['class'] ?? null)
                || ! is_string($component['sha256'] ?? null)) {
                throw new InvalidArgumentException('budget_plan_fact_release_candidate_untrusted');
            }
            $hashes[$component['class']] = new Sha256Hash($component['sha256']);
        }
        $evidence = new ReportDefinitionConformanceEvidence(
            (string) ($document['code'] ?? ''),
            new Sha256Hash((string) ($document['definition_hash'] ?? '')),
            (string) ($document['contract_version'] ?? ''),
            (string) ($document['source_schema_version'] ?? ''),
            new Sha256Hash((string) ($document['fixture_hash'] ?? '')),
            new ReportSourceConformanceEvidence(
                new Sha256Hash((string) ($source['source_hash'] ?? '')),
                (string) ($source['snapshot_kind'] ?? ''),
                (string) ($source['snapshot_id'] ?? ''),
                $source['row_count'] ?? null,
                new Sha256Hash((string) ($source['rows_hash'] ?? '')),
                $source['passed'] ?? null,
                $source['assertion_codes'] ?? null,
            ),
            new ReportFormulaConformanceEvidence(
                (string) ($formula['formula_version'] ?? ''),
                new Sha256Hash((string) ($formula['totals_hash'] ?? '')),
                $formula['passed'] ?? null,
                $formula['assertion_codes'] ?? null,
            ),
            $hashes,
            $document['assertion_count'] ?? null,
            (string) ($document['status'] ?? ''),
            (string) ($document['commit_sha'] ?? ''),
            new DateTimeImmutable((string) ($document['generated_at'] ?? '')),
        );
        $payload = $document;
        unset($payload['digest']);
        if ($payload !== $evidence->canonicalPayload()) {
            throw new InvalidArgumentException('budget_plan_fact_release_candidate_untrusted');
        }

        return $evidence;
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
            'plan_identity',
            'organization_id',
            'period_end',
            'period_start',
            'scenario_identity',
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
            && is_string($identity['scenario_identity'])
            && $identity['scenario_identity'] !== ''
            && is_string($identity['plan_identity'])
            && $identity['plan_identity'] !== '';
    }

    /** @param array<string, mixed> $conformance */
    private function hasRuntimeComponentHashes(array $conformance): bool
    {
        $components = $conformance['component_class_hashes'] ?? null;
        if (! is_array($components) || ! array_is_list($components)) {
            return false;
        }
        $hashes = [];
        foreach ($components as $component) {
            if (! is_array($component)
                || ! is_string($component['class'] ?? null)
                || ! is_string($component['sha256'] ?? null)) {
                return false;
            }
            $hashes[$component['class']] = $component['sha256'];
        }

        return ($hashes[\App\BusinessModules\Features\Budgeting\Services\PlanFactCalculator::class] ?? null) === BudgetPlanFactCandidateContract::FORMULA_HASH
            && ($hashes[\App\BusinessModules\Features\Budgeting\Services\PlanFactSourceSnapshotMaterializer::class] ?? null) === BudgetPlanFactCandidateContract::SOURCE_HASH;
    }

    private function reject(): never
    {
        throw new InvalidArgumentException('budget_plan_fact_release_candidate_untrusted');
    }
}
