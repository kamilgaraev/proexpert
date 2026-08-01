<?php

declare(strict_types=1);

namespace Tests\Support\Reporting\Publication;

use App\BusinessModules\Core\Reporting\Application\Publication\Ed25519ReportPublicationReleaseArtifactSigner;
use App\BusinessModules\Core\Reporting\Application\Publication\Ed25519ReportPublicationReleaseArtifactVerifier;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationReleaseArtifactIssuer;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportPublicationReleaseArtifactVerifier;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationProof;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationReleaseIdentity;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;

final class ReportPublicationReleaseArtifactTestFactory
{
    private const ISSUER = 'most-ci';

    private const KEY_ID = 'reports-release-test-01';

    private const REPOSITORY = 'kamilgaraev/proexpert';

    private const WORKFLOW_REF = '.github/workflows/notification-concurrency.yml@refs/heads/main';

    private const JOB = 'report-publication-release-artifact';

    private const EVENT_NAME = 'push';

    private const REF = 'refs/heads/main';

    private const ENVIRONMENT = 'report-publication-release';

    public static function issue(
        ReportPublicationProof $proof,
        Sha256Hash $candidateManifestHash,
        Sha256Hash $officialManifestHash,
        ReportPublicationReleaseIdentity $release,
        array $evidence,
        int $runAttempt = 1,
        array $provenanceOverrides = [],
    ): string {
        $proofPayload = $proof->payload();

        $provenance = array_replace([
            'artifact_name' => 'report-publication-'.$proofPayload['code'].'-'.$proof->digest()->value,
            'commit_sha' => $release->gitSha,
            'environment' => self::ENVIRONMENT,
            'event_name' => self::EVENT_NAME,
            'job' => self::JOB,
            'ref' => self::REF,
            'repository' => self::REPOSITORY,
            'run_attempt' => $runAttempt,
            'run_id' => $evidence['run_id'],
            'workflow_ref' => self::WORKFLOW_REF,
        ], $provenanceOverrides);
        ksort($provenance, SORT_STRING);

        return self::signer()->issue(
            $provenance,
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
    }

    public static function verifier(): ReportPublicationReleaseArtifactVerifier
    {
        $publicKey = sodium_crypto_sign_publickey(self::keyPair());

        return new Ed25519ReportPublicationReleaseArtifactVerifier([
            self::ISSUER => [
                self::KEY_ID => [
                    'environment' => self::ENVIRONMENT,
                    'event_name' => self::EVENT_NAME,
                    'job' => self::JOB,
                    'public_key_base64' => base64_encode($publicKey),
                    'ref' => self::REF,
                    'repository' => self::REPOSITORY,
                    'workflow_ref' => self::WORKFLOW_REF,
                ],
            ],
        ]);
    }

    public static function issuer(): ReportPublicationReleaseArtifactIssuer
    {
        return new ReportPublicationReleaseArtifactIssuer(self::signer(), self::verifier());
    }

    private static function signer(): Ed25519ReportPublicationReleaseArtifactSigner
    {
        return new Ed25519ReportPublicationReleaseArtifactSigner(
            self::ISSUER,
            self::KEY_ID,
            sodium_crypto_sign_secretkey(self::keyPair()),
        );
    }

    private static function keyPair(): string
    {
        return sodium_crypto_sign_seed_keypair(
            hash('sha256', 'most-report-publication-release-artifact-test-key', true),
        );
    }
}
