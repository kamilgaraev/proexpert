<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Publication;

use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\Publication\ReportPublicationFixtureFactory;
use Tests\Support\Reporting\Publication\ReportPublicationReleaseArtifactTestFactory;

final class ReportPublicationReleaseArtifactIssuerTest extends TestCase
{
    public function test_trusted_ci_context_builds_proof_evidence_and_signed_release_as_one_bundle(): void
    {
        $eligible = ReportPublicationFixtureFactory::eligible()['eligible'];
        $bundle = ReportPublicationReleaseArtifactTestFactory::issuer()->issue(
            $eligible->proof->payload(),
            new Sha256Hash(str_repeat('d', 64)),
            new Sha256Hash(str_repeat('e', 64)),
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
        $eligible = ReportPublicationFixtureFactory::eligible()['eligible'];
        $context = $this->context();
        $context['event_name'] = 'pull_request';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_publication_release_context_untrusted');

        ReportPublicationReleaseArtifactTestFactory::issuer()->issue(
            $eligible->proof->payload(),
            new Sha256Hash(str_repeat('d', 64)),
            new Sha256Hash(str_repeat('e', 64)),
            $context,
        );
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
