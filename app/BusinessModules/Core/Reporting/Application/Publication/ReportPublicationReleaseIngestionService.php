<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportPublicationFeatureStore;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportPublicationRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportPublicationReleaseArtifactVerifier;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationReleaseBundle;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationReleaseIdentity;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportPublicationFeatureMode;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ReportPublicationReleaseIngestionService
{
    public function __construct(
        private ReportPublicationReleaseBundleFileLoader $bundles,
        private ReportPublicationReleaseArtifactVerifier $artifacts,
        private ReportPublicationEligibilityService $eligibility,
        private ReportPublicationRegistry $publications,
        private ReportPublicationFeatureStore $features,
    ) {}

    public function ingest(
        string $proofPath,
        string $artifactPath,
        string $trustedDirectory,
        ReportPublicationReleaseAdmission $admission,
        ReportPublicationFeatureMode $mode = ReportPublicationFeatureMode::OFF,
        array $organizationAllowlist = [],
        array $userAllowlist = [],
    ): PublishedReportDefinition {
        $bundle = $this->bundles->load($proofPath, $artifactPath, $trustedDirectory);
        $release = $this->assertTrustedBundle($bundle, $admission);
        $proof = $bundle->proof;
        $publication = $this->publications->promote($this->eligibility->evaluate(
            $admission->candidate,
            $admission->candidateDocument,
            $admission->binding,
            $admission->evidence,
            $proof,
            new \App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash(
                hash('sha256', $admission->candidateManifestBytes),
            ),
            new \App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash(
                hash('sha256', $admission->officialManifestBytes),
            ),
            $release,
            $bundle->artifactBytes,
            $admission->previous,
        )->publication());
        if ($mode !== ReportPublicationFeatureMode::OFF) {
            $identity = $publication->publicationIdentity;
            if ($identity === null) {
                throw new InvalidArgumentException('report_publication_ingestion_identity_missing');
            }
            $this->features->configure($identity, $mode, $organizationAllowlist, $userAllowlist);
        }

        return $publication;
    }

    private function assertTrustedBundle(
        ReportPublicationReleaseBundle $bundle,
        ReportPublicationReleaseAdmission $admission,
    ): ReportPublicationReleaseIdentity {
        $artifact = $this->artifacts->verify($bundle->artifactBytes);
        $proof = $bundle->proof->payload();
        $subject = $artifact->payload()['subject'];
        $evidence = $artifact->payload()['evidence'];
        if (! hash_equals($admission->candidate->code, $proof['code'])
            || ! hash_equals($proof['code'], $subject['code'])
            || ! hash_equals($bundle->proof->digest()->value, $subject['proof_sha256'])
            || ! hash_equals($proof['candidate_manifest_sha256'], $subject['candidate_manifest_sha256'])
            || ! hash_equals($proof['candidate_definition_sha256'], $subject['candidate_definition_sha256'])
            || ! hash_equals($proof['binding_sha256'], $subject['binding_sha256'])
            || ! hash_equals($proof['conformance_evidence_sha256'], $subject['conformance_evidence_sha256'])
            || ! hash_equals($proof['release']['git_sha'], $subject['release_git_sha'])
            || ! hash_equals($proof['release']['created_at_utc'], $subject['release_created_at_utc'])
            || ! hash_equals($proof['release']['approver_identity'], $subject['approver_identity'])
            || ! hash_equals($proof['ci']['run_id'], $evidence['run_id'])
            || ! hash_equals($proof['ci']['commit_sha'], $evidence['commit_sha'])
            || ! hash_equals($proof['ci']['completed_at_utc'], $evidence['completed_at_utc'])
            || ! hash_equals($proof['ci']['suite_sha256'], hash('sha256', CanonicalJson::encode($evidence)))
            || array_keys($evidence['checks']) !== $proof['ci']['required_checks']) {
            throw new InvalidArgumentException('report_publication_release_input_untrusted');
        }

        return new ReportPublicationReleaseIdentity(
            $proof['release']['git_sha'],
            new DateTimeImmutable($proof['release']['created_at_utc']),
            $proof['release']['approver_identity'],
        );
    }
}
