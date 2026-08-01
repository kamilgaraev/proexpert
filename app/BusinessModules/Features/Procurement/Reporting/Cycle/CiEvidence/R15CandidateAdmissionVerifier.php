<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\CiEvidence;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use InvalidArgumentException;

final class R15CandidateAdmissionVerifier
{
    private const FILES = [
        'r15-candidate-manifest.json',
        'r15-conformance-evidence.json',
        'r15-proof-template.json',
        'r15-release-request.json',
    ];

    public function verify(string $directory, string $commitSha, string $repository, string $ref, string $job): void
    {
        if (preg_match('/^[a-f0-9]{40}$/D', $commitSha) !== 1
            || $repository !== 'kamilgaraev/proexpert'
            || $ref !== 'refs/heads/main'
            || $job !== 'procurement-cycle-r15-protected-admission') {
            $this->reject();
        }
        $root = realpath($directory);
        if ($root === false || is_link($directory)) {
            $this->reject();
        }
        $documents = [];
        foreach (self::FILES as $file) {
            $path = $root.DIRECTORY_SEPARATOR.$file;
            if (is_link($path) || ! is_file($path)) {
                $this->reject();
            }
            $bytes = file_get_contents($path);
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
        $request = $documents['r15-release-request.json'];
        $artifactPaths = [
            'candidate_manifest' => 'r15-candidate-manifest.json',
            'conformance_evidence' => 'r15-conformance-evidence.json',
            'proof_template' => 'r15-proof-template.json',
        ];
        $requiredChecks = ['r15_formula_contract', 'r15_postgresql_contract', 'r15_runtime_contract'];
        if (($candidate['code'] ?? null) !== 'procurement_cycle'
            || ($candidate['publication_status'] ?? null) !== 'blocked'
            || ($candidate['generated_from_commit'] ?? null) !== $commitSha
            || ($conformance['commit_sha'] ?? null) !== $commitSha
            || ($proof['ci']['commit_sha'] ?? null) !== $commitSha
            || ($request['commit_sha'] ?? null) !== $commitSha
            || ! hash_equals(hash('sha256', CanonicalJson::encode($candidate)), (string) ($proof['candidate_manifest_sha256'] ?? ''))
            || ! hash_equals(hash('sha256', CanonicalJson::encode($conformance)), (string) ($proof['conformance_evidence_sha256'] ?? ''))
            || ! hash_equals(hash('sha256', CanonicalJson::encode($proof)), (string) ($request['proof_sha256'] ?? ''))
            || ($proof['ci']['required_checks'] ?? null) !== $requiredChecks
            || ($request['artifact_paths'] ?? null) !== $artifactPaths) {
            $this->reject();
        }
    }

    private function reject(): never
    {
        throw new InvalidArgumentException('r15_protected_admission_untrusted');
    }
}
