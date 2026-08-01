<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Publication;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationProof;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\Publication\ReportPublicationFixtureFactory;
use Tests\Support\Reporting\Publication\ReportPublicationReleaseArtifactTestFactory;

final class ReportPublicationReleaseArtifactIssuerTest extends TestCase
{
    public function test_trusted_ci_context_builds_proof_evidence_and_signed_release_as_one_bundle(): void
    {
        $fixture = ReportPublicationFixtureFactory::eligible(productionComponents: true);
        $bundle = ReportPublicationReleaseArtifactTestFactory::issuer($fixture['eligibility_service'])->issue(
            ReportPublicationReleaseArtifactTestFactory::releaseAdmission($fixture, productionSafe: true),
            $this->context(),
        );
        $artifact = ReportPublicationReleaseArtifactTestFactory::verifier()->verify($bundle->artifactBytes);

        self::assertSame(
            [
                'binding_contract',
                'drill_down_contract',
                'export_xlsx_contract',
                'formula_contract',
                'postgresql_contract',
                'rbac_contract',
                'source_contract',
            ],
            $bundle->proof->payload()['ci']['required_checks'],
        );
        self::assertSame(hash('sha256', $artifact->evidenceBytes()), $bundle->proof->payload()['ci']['suite_sha256']);
        self::assertSame($bundle->proof->digest()->value, $artifact->payload()['subject']['proof_sha256']);
        self::assertSame('push', $artifact->payload()['provenance']['event_name']);
        self::assertSame('refs/heads/main', $artifact->payload()['provenance']['ref']);
    }

    public function test_untrusted_pull_request_context_is_rejected_before_signing(): void
    {
        $fixture = ReportPublicationFixtureFactory::eligible(productionComponents: true);
        $context = $this->context();
        $context['event_name'] = 'pull_request';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_publication_release_context_untrusted');

        ReportPublicationReleaseArtifactTestFactory::issuer($fixture['eligibility_service'])->issue(
            ReportPublicationReleaseArtifactTestFactory::releaseAdmission($fixture, productionSafe: true),
            $context,
        );
    }

    public function test_signed_bundle_is_not_returned_when_the_full_publication_gate_rejects_the_request(): void
    {
        $fixture = ReportPublicationFixtureFactory::eligible(productionComponents: true);
        $eligible = $fixture['eligible'];
        $proofTemplate = $eligible->proof->payload();
        $proofTemplate['binding_sha256'] = hash('sha256', 'invalid-binding');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_publication_ineligible');

        ReportPublicationReleaseArtifactTestFactory::issuer($fixture['eligibility_service'])->issue(
            ReportPublicationReleaseArtifactTestFactory::releaseAdmission(
                $fixture,
                ReportPublicationProof::fromArray($proofTemplate),
                productionSafe: true,
            ),
            $this->context(),
        );
    }

    public function test_unverified_check_cannot_be_declared_passed_from_the_proof_template(): void
    {
        $fixture = ReportPublicationFixtureFactory::eligible(productionComponents: true);
        $admission = ReportPublicationReleaseArtifactTestFactory::releaseAdmission($fixture, productionSafe: true);
        $admission = new \App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationReleaseAdmission(
            $admission->candidate,
            $admission->candidateDocument,
            $admission->binding,
            $admission->evidence,
            $admission->proofTemplate,
            array_values(array_diff($admission->verifiedChecks, ['binding_contract'])),
            $admission->candidateManifestBytes,
            $admission->officialManifestBytes,
            $admission->previous,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_publication_release_context_untrusted');

        ReportPublicationReleaseArtifactTestFactory::issuer($fixture['eligibility_service'])->issue(
            $admission,
            $this->context(),
        );
    }

    public function test_public_issuer_rejects_test_admission_before_signing(): void
    {
        $fixture = ReportPublicationFixtureFactory::eligible();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_publication_release_request_sentinel_hash');

        ReportPublicationReleaseArtifactTestFactory::issuer($fixture['eligibility_service'])->issue(
            ReportPublicationReleaseArtifactTestFactory::releaseAdmission($fixture),
            $this->context(),
        );
    }

    public function test_production_admission_rejects_sentinel_proof_hashes(): void
    {
        $fixture = ReportPublicationFixtureFactory::eligible();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_publication_release_request_sentinel_hash');

        ReportPublicationReleaseArtifactTestFactory::releaseAdmission($fixture)->assertProductionSafe();
    }

    public function test_production_admission_rejects_test_namespace_components(): void
    {
        $fixture = ReportPublicationFixtureFactory::eligible();
        $payload = $fixture['eligible']->proof->payload();
        $sequence = 0;
        array_walk_recursive($payload, static function (mixed &$value) use (&$sequence): void {
            if (is_string($value) && preg_match('/^([a-f0-9])\1{63}$/D', $value) === 1) {
                $value = hash('sha256', 'release-proof-field-'.++$sequence);
            }
        });

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_publication_release_request_test_component');

        ReportPublicationReleaseArtifactTestFactory::releaseAdmission(
            $fixture,
            ReportPublicationProof::fromArray($payload),
        )->assertProductionSafe();
    }

    private function context(): array
    {
        return [
            'actor_identity' => 'release-bot@most',
            'commit_sha' => str_repeat('a', 40),
            'completed_at_utc' => '2026-08-01T02:03:04.654321Z',
            'environment' => 'report-publication-release',
            'event_name' => 'push',
            'job' => 'report-publication-release-artifact',
            'ref' => 'refs/heads/main',
            'repository' => 'kamilgaraev/proexpert',
            'run_attempt' => 1,
            'run_id' => 'ci-1001',
            'workflow_ref' => '.github/workflows/notification-concurrency.yml@refs/heads/main',
        ];
    }
}
