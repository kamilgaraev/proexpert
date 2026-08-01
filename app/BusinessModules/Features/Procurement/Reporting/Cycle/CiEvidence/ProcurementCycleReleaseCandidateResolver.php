<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\CiEvidence;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use InvalidArgumentException;

final class ProcurementCycleReleaseCandidateResolver
{
    private const FILES = ['r15-candidate-manifest.json', 'r15-conformance-evidence.json', 'r15-proof-template.json', 'r15_release_request.json'];

    private const CHECKS = ['binding_contract', 'drill_down_contract', 'export_csv_contract', 'export_pdf_contract', 'export_xlsx_contract', 'formula_contract', 'postgresql_contract', 'rbac_contract', 'source_contract'];

    /** @return array<string,array<string,mixed>> */
    public function resolve(string $directory, string $commitSha): array
    {
        if (preg_match('/^[a-f0-9]{40}$/D', $commitSha) !== 1) {
            $this->reject();
        }
        $root = realpath($directory);
        if ($root === false || is_link($directory)) {
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
        $candidate = $documents['r15-candidate-manifest.json'];
        $conformance = $documents['r15-conformance-evidence.json'];
        $proof = $documents['r15-proof-template.json'];
        $request = $documents['r15_release_request.json'];
        $definition = $candidate['candidate_definition'] ?? null;
        if (! is_array($definition) || array_is_list($definition)
            || ($candidate['code'] ?? null) !== 'procurement_cycle'
            || ($candidate['publication_status'] ?? null) !== 'candidate'
            || ($candidate['generated_from_commit'] ?? null) !== $commitSha
            || ! hash_equals(hash('sha256', CanonicalJson::encode($definition)), (string) ($candidate['candidate_definition_sha256'] ?? ''))
            || ! hash_equals((string) ($candidate['candidate_definition_sha256'] ?? ''), (string) ($proof['candidate_definition_sha256'] ?? ''))
            || ! hash_equals(hash('sha256', CanonicalJson::encode($candidate)), (string) ($proof['candidate_manifest_sha256'] ?? ''))
            || ! hash_equals(hash('sha256', CanonicalJson::encode($conformance)), (string) ($proof['conformance_evidence_sha256'] ?? ''))
            || ($conformance['commit_sha'] ?? null) !== $commitSha
            || ($proof['ci']['commit_sha'] ?? null) !== $commitSha
            || ($proof['ci']['required_checks'] ?? null) !== self::CHECKS
            || ($proof['definition'] ?? null) !== $definition
            || ($request['request_id'] ?? null) !== 'r15_release_request'
            || ($request['commit_sha'] ?? null) !== $commitSha
            || ! hash_equals(hash('sha256', CanonicalJson::encode($proof)), (string) ($request['proof_sha256'] ?? ''))
            || ($request['artifact_paths'] ?? null) !== ['candidate_manifest' => 'r15-candidate-manifest.json', 'conformance_evidence' => 'r15-conformance-evidence.json', 'proof_template' => 'r15-proof-template.json']) {
            $this->reject();
        }
        $this->assertDefinition($definition);

        return $documents;
    }

    /** @param array<string,mixed> $definition */
    private function assertDefinition(array $definition): void
    {
        $keys = array_keys($definition);
        sort($keys, SORT_STRING);
        if ($keys !== ['capabilities', 'code', 'columns', 'filters', 'formats', 'grain', 'permissions', 'readiness', 'runtime', 'semantic_fingerprints', 'sorts', 'versions']
            || ($definition['code'] ?? null) !== 'procurement_cycle'
            || ! is_array($definition['filters']) || ! is_array($definition['columns']) || ! is_array($definition['sorts'])
            || ($definition['formats'] ?? null) !== ['csv', 'pdf', 'xlsx']
            || ($definition['sorts'] ?? null) !== [['direction' => 'asc', 'id' => 'cohort_date']]
            || ($definition['readiness'] ?? null) !== ['delivery' => 'verified', 'formula' => 'verified', 'publication' => 'candidate', 'source' => 'verified']) {
            $this->reject();
        }
    }


    private function reject(): never
    {
        throw new InvalidArgumentException('r15_release_candidate_untrusted');
    }
}
