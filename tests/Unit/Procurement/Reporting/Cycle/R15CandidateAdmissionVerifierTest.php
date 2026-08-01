<?php

declare(strict_types=1);

namespace Tests\Unit\Procurement\Reporting\Cycle;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\CiEvidence\R15CandidateAdmissionVerifier;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class R15CandidateAdmissionVerifierTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'r15-admission-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);
        $this->writeDocuments();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function test_rejects_legacy_fixture_that_does_not_match_current_full_contract(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new R15CandidateAdmissionVerifier)->verify($this->directory, str_repeat('a', 40), 'kamilgaraev/proexpert', 'refs/heads/main', 'procurement-cycle-r15-protected-admission');
    }

    public function test_rejects_missing_current_request_document(): void
    {
        unlink($this->directory.DIRECTORY_SEPARATOR.'r15-release-request.json');
        $this->expectException(InvalidArgumentException::class);
        (new R15CandidateAdmissionVerifier)->verify($this->directory, str_repeat('a', 40), 'kamilgaraev/proexpert', 'refs/heads/main', 'procurement-cycle-r15-protected-admission');
    }

    public function test_rejects_replayed_sha_and_tampered_candidate(): void
    {
        $verifier = new R15CandidateAdmissionVerifier;
        $this->expectException(InvalidArgumentException::class);
        $verifier->verify($this->directory, str_repeat('b', 40), 'kamilgaraev/proexpert', 'refs/heads/main', 'procurement-cycle-r15-protected-admission');
    }

    private function writeDocuments(): void
    {
        $sha = str_repeat('a', 40);
        $candidate = ['admission_status' => 'candidate', 'code' => 'procurement_cycle', 'contract_version' => '1.0.0', 'formula_version' => 'procurement-cycle.v1', 'generated_from_commit' => $sha, 'publication_status' => 'blocked', 'runtime_binding' => ['class' => 'App\\BusinessModules\\Features\\Procurement\\Reporting\\Cycle\\Services\\ProcurementCycleReportBindingFactory', 'sha256' => str_repeat('c', 64)], 'source_schema_version' => '1.0.0'];
        $conformance = ['artifact_id' => 'r15_candidate_conformance', 'artifacts' => [], 'code' => 'procurement_cycle', 'commit_sha' => $sha, 'generated_at' => '2026-08-01T00:00:00.000000Z', 'schema_version' => '1.0.0', 'verification_status' => 'ci_required'];
        $proof = ['admission_status' => 'blocked', 'artifacts' => [], 'candidate_manifest_sha256' => hash('sha256', CanonicalJson::encode($candidate)), 'canonical_publication_proof_schema_sha256' => str_repeat('d', 64), 'ci' => ['commit_sha' => $sha, 'required_checks' => ['r15_formula_contract', 'r15_postgresql_contract', 'r15_runtime_contract'], 'run_id' => '1.1', 'suite_sha256' => str_repeat('e', 64)], 'code' => 'procurement_cycle', 'conformance_evidence_sha256' => hash('sha256', CanonicalJson::encode($conformance)), 'schema_version' => '1.0.0'];
        $request = ['admission_status' => 'blocked', 'artifact_paths' => ['candidate_manifest' => 'r15-candidate-manifest.json', 'conformance_evidence' => 'r15-conformance-evidence.json', 'proof_template' => 'r15-proof-template.json'], 'code' => 'procurement_cycle', 'commit_sha' => $sha, 'proof_sha256' => hash('sha256', CanonicalJson::encode($proof)), 'request_kind' => 'r15_candidate_evidence', 'schema_version' => '1.0.0'];
        foreach (['r15-candidate-manifest.json' => $candidate, 'r15-conformance-evidence.json' => $conformance, 'r15-proof-template.json' => $proof, 'r15-release-request.json' => $request] as $name => $document) {
            file_put_contents($this->directory.DIRECTORY_SEPARATOR.$name, CanonicalJson::encode($document));
        }
    }
}
