<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Publication;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationReleaseArtifact;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\Publication\ReportPublicationFixtureFactory;
use Tests\Support\Reporting\Publication\ReportPublicationReleaseArtifactTestFactory;

final class ReportPublicationReleaseArtifactTest extends TestCase
{
    public function test_trusted_issuer_signature_and_exact_release_provenance_are_verified(): void
    {
        $eligible = ReportPublicationFixtureFactory::eligible()['eligible'];

        $artifact = ReportPublicationReleaseArtifactTestFactory::verifier()
            ->verify($eligible->releaseArtifactBytes);

        self::assertSame($eligible->proofHash->value, $artifact->payload()['subject']['proof_sha256']);
        self::assertSame(
            $eligible->officialManifestHash->value,
            $artifact->payload()['subject']['official_manifest_sha256'],
        );
        self::assertSame(
            '.github/workflows/notification-concurrency.yml@refs/heads/main',
            $artifact->payload()['provenance']['workflow_ref'],
        );
        self::assertSame('push', $artifact->payload()['provenance']['event_name']);
        self::assertSame('refs/heads/main', $artifact->payload()['provenance']['ref']);
        self::assertSame(
            'report-publication-release',
            $artifact->payload()['provenance']['environment'],
        );
    }

    #[DataProvider('untrustedExecutionBoundaryProvider')]
    public function test_signed_artifact_outside_the_protected_execution_boundary_is_rejected(
        array $provenanceOverrides,
    ): void {
        $fixture = ReportPublicationFixtureFactory::eligible();
        $artifactPayload = json_decode($fixture['eligible']->releaseArtifactBytes, true, 512, JSON_THROW_ON_ERROR);
        $artifact = ReportPublicationReleaseArtifactTestFactory::issue(
            $fixture['eligible']->proof,
            $fixture['eligible']->candidateManifestHash,
            $fixture['eligible']->officialManifestHash,
            $fixture['eligible']->release,
            $artifactPayload['evidence'],
            provenanceOverrides: $provenanceOverrides,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_publication_release_artifact_untrusted');

        ReportPublicationReleaseArtifactTestFactory::verifier()->verify($artifact);
    }

    public static function untrustedExecutionBoundaryProvider(): iterable
    {
        yield 'pull request event' => [['event_name' => 'pull_request']];
        yield 'pull request ref' => [['ref' => 'refs/pull/123/merge']];
        yield 'unprotected environment' => [['environment' => 'report-publication-preview']];
        yield 'different repository' => [['repository' => 'attacker/proexpert']];
    }

    #[DataProvider('tamperProvider')]
    public function test_any_signed_subject_or_provenance_tamper_fails_closed(callable $mutate): void
    {
        $eligible = ReportPublicationFixtureFactory::eligible()['eligible'];
        $payload = json_decode($eligible->releaseArtifactBytes, true, 512, JSON_THROW_ON_ERROR);
        $mutate($payload);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_publication_release_artifact_untrusted');

        ReportPublicationReleaseArtifactTestFactory::verifier()->verify(CanonicalJson::encode($payload));
    }

    public static function tamperProvider(): iterable
    {
        yield 'official manifest' => [static function (array &$payload): void {
            $payload['subject']['official_manifest_sha256'] = str_repeat('f', 64);
        }];
        yield 'proof digest' => [static function (array &$payload): void {
            $payload['subject']['proof_sha256'] = str_repeat('f', 64);
        }];
        yield 'workflow provenance' => [static function (array &$payload): void {
            $payload['provenance']['workflow_ref'] = '.github/workflows/other.yml@refs/heads/main';
        }];
        yield 'issuer key' => [static function (array &$payload): void {
            $payload['key_id'] = 'reports-release-unknown';
        }];
        yield 'check status' => [static function (array &$payload): void {
            $payload['evidence']['checks']['postgresql_contract'] = 'failed';
        }];
    }

    public function test_structurally_impossible_calendar_timestamp_is_rejected(): void
    {
        $eligible = ReportPublicationFixtureFactory::eligible()['eligible'];
        $payload = json_decode($eligible->releaseArtifactBytes, true, 512, JSON_THROW_ON_ERROR);
        $payload['subject']['release_created_at_utc'] = '2026-02-31T12:00:00.000000Z';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_publication_release_artifact_invalid');

        ReportPublicationReleaseArtifact::fromCanonicalBytes(CanonicalJson::encode($payload));
    }
}
