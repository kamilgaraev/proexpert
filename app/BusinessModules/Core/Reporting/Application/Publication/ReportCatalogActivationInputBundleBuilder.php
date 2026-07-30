<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogActivationInputBundle;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/** Builds the portable, candidate-only activation hand-off. */
final class ReportCatalogActivationInputBundleBuilder
{
    private const SCHEMA = 'docs/reports/contracts/report-catalog-activation-input-bundle.schema.json';

    /** @var array<string, string> */
    private array $artifacts;

    /**
     * @param array<string, string> $artifacts Relative paths keyed by plan_2, plan_3_candidate and plan_3_evidence.
     */
    public function __construct(private readonly string $repositoryRoot, array $artifacts = [])
    {
        $this->artifacts = $artifacts ?: [
            'plan_2' => 'build/reports/plan-2-wave-1-evidence.json',
            'plan_3_candidate' => 'build/reports/waves-2-3-candidate-contribution.json',
            'plan_3_evidence' => 'build/reports/plan-3-waves-2-3-evidence.json',
        ];
        if (array_keys($this->artifacts) !== ['plan_2', 'plan_3_candidate', 'plan_3_evidence']) {
            throw new RuntimeException('report_catalog_activation_inputs_artifact_catalog_invalid');
        }
    }

    public function build(string $releaseSha, DateTimeImmutable $generatedAt): ReportCatalogActivationInputBundle
    {
        $this->assertSha($releaseSha);
        $generatedAt = $this->canonicalTime($generatedAt);
        $documents = [];
        $sourceArtifacts = [];
        foreach ($this->artifacts as $name => $relative) {
            $path = $this->inputPath($relative);
            $bytes = $this->read($path);
            $document = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($document) || array_is_list($document) || CanonicalJson::encode($document)."\n" !== $bytes) {
                throw new RuntimeException('report_catalog_activation_inputs_artifact_invalid');
            }
            $documents[$name] = $document;
            $sourceArtifacts[] = ['name' => $name, 'path' => $relative, 'sha256' => hash('sha256', $bytes)];
        }

        $plan2 = $documents['plan_2'];
        $plan3Candidate = $documents['plan_3_candidate'];
        $plan3Evidence = $documents['plan_3_evidence'];
        foreach ([$plan2, $plan3Candidate, $plan3Evidence] as $document) {
            if (($document['release_sha'] ?? null) !== $releaseSha) {
                throw new RuntimeException('report_catalog_activation_inputs_release_sha_mismatch');
            }
            $this->assertNotFutureEvidence($document, $generatedAt);
        }
        if (($plan2['counts'] ?? null) !== [
            'candidate_definitions' => 12,
            'bindings' => 12,
            'conformance_records' => 12,
            'property_families' => 12,
            'property_seeds' => 6000,
        ]) {
            throw new RuntimeException('report_catalog_activation_inputs_plan_2_counts_invalid');
        }

        $candidateManifest = $this->requiredArray($plan2, 'candidate_manifest');
        $candidatePayloads = array_merge(
            $this->records($plan2, 'candidate_payloads', 12),
            $this->records($plan3Candidate, 'candidate_payloads', 16),
        );
        $validationItems = array_merge(
            $this->records($plan2, 'validation_items', 12),
            $this->records($plan3Evidence, 'validation_items', 16),
        );
        $bindings = array_merge(
            $this->records($plan2, 'bindings', 12),
            $this->records($plan3Candidate, 'bindings', 16),
        );
        $conformance = array_merge(
            $this->records($plan2, 'conformance_records', 12),
            $this->records($plan3Evidence, 'conformance_records', 16),
        );
        $this->assertCodeSets($candidatePayloads, $validationItems, $bindings, $conformance);

        $planEvidence = [$plan2, $plan3Candidate, $plan3Evidence];
        $sectionHashes = [
            'candidate_manifest' => hash('sha256', CanonicalJson::encode($candidateManifest)),
            'candidate_payloads' => hash('sha256', CanonicalJson::encode($candidatePayloads)),
            'validation_items' => hash('sha256', CanonicalJson::encode($validationItems)),
            'bindings' => hash('sha256', CanonicalJson::encode($bindings)),
            'conformance_records' => hash('sha256', CanonicalJson::encode($conformance)),
            'plan_evidence_documents' => hash('sha256', CanonicalJson::encode($planEvidence)),
        ];
        $counts = [
            'plan_2_candidates' => 12, 'plan_3_candidates' => 16, 'candidate_payloads' => 28,
            'validation_items' => 28, 'passed_validations' => 28, 'bindings' => 28, 'conformance_records' => 28,
        ];

        return new ReportCatalogActivationInputBundle(
            'report_catalog_activation_inputs', 'activation_inputs_passed', $releaseSha, $sourceArtifacts,
            $candidateManifest, $candidatePayloads, $validationItems, $bindings, $conformance, $planEvidence,
            $counts, $sectionHashes, $generatedAt,
        );
    }

    /** @param array<int, array<string, mixed>> ...$sections */
    private function assertCodeSets(array ...$sections): void
    {
        $expected = null;
        foreach ($sections as $section) {
            if (count($section) !== 28) {
                throw new RuntimeException('report_catalog_activation_inputs_count_invalid');
            }
            $codes = [];
            foreach ($section as $item) {
                $code = $item['code'] ?? null;
                if (! is_string($code) || isset($codes[$code])) {
                    throw new RuntimeException('report_catalog_activation_inputs_code_set_invalid');
                }
                $codes[$code] = true;
                if (($item['passed'] ?? true) !== true || ($item['failure_codes'] ?? []) !== []) {
                    throw new RuntimeException('report_catalog_activation_inputs_validation_invalid');
                }
            }
            $codes = array_keys($codes);
            if ($expected === null) {
                $expected = $codes;
            } elseif ($expected !== $codes) {
                throw new RuntimeException('report_catalog_activation_inputs_code_order_invalid');
            }
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function records(array $document, string $key, int $count): array
    {
        $records = $document[$key] ?? null;
        if (! is_array($records) || ! array_is_list($records) || count($records) !== $count) {
            throw new RuntimeException('report_catalog_activation_inputs_records_invalid');
        }
        foreach ($records as $record) {
            if (! is_array($record)) {
                throw new RuntimeException('report_catalog_activation_inputs_records_invalid');
            }
        }
        return $records;
    }

    /** @return array<string, mixed> */
    private function requiredArray(array $document, string $key): array
    {
        if (! isset($document[$key]) || ! is_array($document[$key]) || array_is_list($document[$key])) {
            throw new RuntimeException('report_catalog_activation_inputs_artifact_invalid');
        }
        return $document[$key];
    }

    private function inputPath(string $relative): string
    {
        if (preg_match('#^(?:[A-Za-z]:)?[/\\\\]|(?:.*[/\\\\])?\.\.(?:[/\\\\]|$)#D', $relative) === 1) {
            throw new RuntimeException('report_catalog_activation_inputs_path_invalid');
        }
        $root = realpath($this->repositoryRoot);
        $path = $root === false ? false : realpath($root.DIRECTORY_SEPARATOR.$relative);
        if ($root === false || $path === false || is_link($path) || ! is_file($path)
            || ! str_starts_with(str_replace('\\', '/', $path), str_replace('\\', '/', $root).'/')) {
            throw new RuntimeException('report_catalog_activation_inputs_path_invalid');
        }
        return $path;
    }

    private function read(string $path): string
    {
        $bytes = file_get_contents($path);
        if (! is_string($bytes)) {
            throw new RuntimeException('report_catalog_activation_inputs_read_failed');
        }
        return $bytes;
    }

    private function canonicalTime(DateTimeImmutable $time): DateTimeImmutable
    {
        $formatted = $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z');
        return new DateTimeImmutable($formatted);
    }

    private function assertNotFutureEvidence(array $document, DateTimeImmutable $generatedAt): void
    {
        foreach (['generated_at', 'executed_at'] as $key) {
            if (! isset($document[$key])) {
                continue;
            }
            try {
                $time = new DateTimeImmutable((string) $document[$key]);
            } catch (\Throwable) {
                throw new RuntimeException('report_catalog_activation_inputs_time_invalid');
            }
            if ($time > $generatedAt) {
                throw new RuntimeException('report_catalog_activation_inputs_time_invalid');
            }
        }
    }

    private function assertSha(string $sha): void
    {
        if (preg_match('/^[a-f0-9]{40}$/D', $sha) !== 1) {
            throw new RuntimeException('report_catalog_activation_inputs_release_sha_invalid');
        }
    }
}
