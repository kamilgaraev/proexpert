<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportPublicationReleaseArtifactVerifier;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationProof;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationReleaseBundle;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationReleaseIdentity;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use InvalidArgumentException;

final readonly class ReportPublicationReleaseArtifactIssuer
{
    private const BASE_CHECKS = [
        'binding_contract',
        'drill_down_contract',
        'formula_contract',
        'rbac_contract',
        'source_contract',
    ];

    private const CONTEXT_KEYS = [
        'actor_identity',
        'commit_sha',
        'completed_at_utc',
        'environment',
        'event_name',
        'job',
        'ref',
        'repository',
        'run_attempt',
        'run_id',
        'workflow_ref',
    ];

    public function __construct(
        private Ed25519ReportPublicationReleaseArtifactSigner $signer,
        private ReportPublicationReleaseArtifactVerifier $verifier,
        private ReportPublicationReleaseEligibilityGate $gate,
    ) {}

    public function issue(
        ReportPublicationReleaseAdmission $admission,
        array $context,
    ): ReportPublicationReleaseBundle {
        $admission->assertProductionSafe();

        return $this->issueAdmitted($admission, $context);
    }

    private function issueAdmitted(
        ReportPublicationReleaseAdmission $admission,
        array $context,
    ): ReportPublicationReleaseBundle {
        $this->assertContext($context);
        $release = new ReportPublicationReleaseIdentity(
            $context['commit_sha'],
            new \DateTimeImmutable($context['completed_at_utc']),
            $context['actor_identity'],
        );
        $proofTemplate = $admission->proofTemplate->payload();
        $candidateManifestHash = new Sha256Hash(hash('sha256', $admission->candidateManifestBytes));
        $officialManifestHash = new Sha256Hash(hash('sha256', $admission->officialManifestBytes));
        $requiredChecks = $this->requiredChecks($proofTemplate);
        $verifiedChecks = $admission->verifiedChecks;
        if ($verifiedChecks !== $requiredChecks) {
            throw new InvalidArgumentException('report_publication_release_context_untrusted');
        }
        $evidence = [
            'checks' => array_fill_keys($verifiedChecks, 'passed'),
            'commit_sha' => $context['commit_sha'],
            'completed_at_utc' => $context['completed_at_utc'],
            'run_id' => $context['run_id'],
        ];
        $proofPayload = $proofTemplate;
        $proofPayload['candidate_manifest_sha256'] = $candidateManifestHash->value;
        $proofPayload['ci'] = [
            'commit_sha' => $context['commit_sha'],
            'completed_at_utc' => $context['completed_at_utc'],
            'required_checks' => $requiredChecks,
            'run_id' => $context['run_id'],
            'suite_sha256' => hash('sha256', CanonicalJson::encode($evidence)),
        ];
        $proofPayload['release'] = [
            'approver_identity' => $context['actor_identity'],
            'created_at_utc' => $context['completed_at_utc'],
            'git_sha' => $context['commit_sha'],
        ];
        $proof = ReportPublicationProof::fromArray($proofPayload);
        $artifactName = 'report-publication-'.$proofPayload['code'].'-'.$proof->digest()->value;
        $artifactBytes = $this->signer->issue(
            [
                'artifact_name' => $artifactName,
                'commit_sha' => $context['commit_sha'],
                'environment' => $context['environment'],
                'event_name' => $context['event_name'],
                'job' => $context['job'],
                'ref' => $context['ref'],
                'repository' => $context['repository'],
                'run_attempt' => $context['run_attempt'],
                'run_id' => $context['run_id'],
                'workflow_ref' => $context['workflow_ref'],
            ],
            [
                'approver_identity' => $release->approverIdentity,
                'binding_sha256' => $proofPayload['binding_sha256'],
                'candidate_definition_sha256' => $proofPayload['candidate_definition_sha256'],
                'candidate_manifest_sha256' => $candidateManifestHash->value,
                'code' => $proofPayload['code'],
                'conformance_evidence_sha256' => $proofPayload['conformance_evidence_sha256'],
                'official_manifest_sha256' => $officialManifestHash->value,
                'proof_sha256' => $proof->digest()->value,
                'release_created_at_utc' => $release->createdAtUtc(),
                'release_git_sha' => $release->gitSha,
            ],
            $evidence,
        );
        $this->verifier->verify($artifactBytes);
        $this->gate->assertEligible(
            $admission,
            $proof,
            $release,
            $artifactBytes,
        );

        return new ReportPublicationReleaseBundle($proof, $artifactBytes, $artifactName);
    }

    private function requiredChecks(array $proofTemplate): array
    {
        $exportContracts = $proofTemplate['export_contracts'] ?? null;
        if (! is_array($exportContracts) || $exportContracts === []) {
            throw new InvalidArgumentException('report_publication_release_context_untrusted');
        }
        $required = self::BASE_CHECKS;
        foreach ($exportContracts as $contract) {
            $format = is_array($contract) ? ($contract['format'] ?? null) : null;
            if (! is_string($format) || ! in_array($format, ['csv', 'pdf', 'xlsx'], true)) {
                throw new InvalidArgumentException('report_publication_release_context_untrusted');
            }
            $required[] = 'export_'.$format.'_contract';
        }
        $required[] = 'postgresql_contract';
        $required = array_values(array_unique($required));
        sort($required, SORT_STRING);

        return $required;
    }

    private function assertContext(array $context): void
    {
        $keys = array_keys($context);
        sort($keys, SORT_STRING);
        if ($keys !== self::CONTEXT_KEYS
            || $context['event_name'] !== 'push'
            || $context['ref'] !== 'refs/heads/main'
            || $context['environment'] !== 'report-publication-release'
            || $context['job'] !== 'report-publication-release-artifact'
            || $context['repository'] !== 'kamilgaraev/proexpert'
            || $context['workflow_ref'] !== '.github/workflows/notification-concurrency.yml@refs/heads/main'
            || ! is_int($context['run_attempt'])
            || $context['run_attempt'] < 1) {
            throw new InvalidArgumentException('report_publication_release_context_untrusted');
        }
    }
}
