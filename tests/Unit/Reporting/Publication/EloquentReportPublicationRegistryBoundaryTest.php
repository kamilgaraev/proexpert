<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Publication;

use App\BusinessModules\Core\Reporting\Domain\DTO\EligibleReportPublication;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationProof;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationReleaseIdentity;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;
use App\BusinessModules\Core\Reporting\Infrastructure\Publication\EloquentReportPublicationRegistry;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\Publication\ReportPublicationFixtureFactory;
use Tests\Support\Reporting\Publication\ReportPublicationReleaseArtifactTestFactory;

final class EloquentReportPublicationRegistryBoundaryTest extends TestCase
{
    public function test_current_materializes_the_exact_persisted_candidate_without_a_live_candidate_registry(): void
    {
        $fixture = ReportPublicationFixtureFactory::eligible();
        $eligible = $fixture['eligible'];
        $query = $this->createMock(Builder::class);
        $query->method('where')->willReturnSelf();
        $query->method('first')->willReturn((object) $this->persistedRow($eligible));
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('table')->willReturn($query);
        $registry = new EloquentReportPublicationRegistry(
            $connection,
            $fixture['eligibility_service'],
            new ReportDefinitionFactory,
        );

        $published = $registry->current($eligible->candidate->code);

        self::assertNotNull($published);
        self::assertSame($eligible->candidate->definitionHash->value, $published->definitionHash->value);
        self::assertSame($eligible->candidate->definition->columns, $published->definition->columns);
        self::assertSame('01J00000000000000000000000', $published->publicationIdentity?->publicationId);
    }

    public function test_publicly_constructed_eligible_dto_cannot_bypass_full_eligibility_verification(): void
    {
        $fixture = ReportPublicationFixtureFactory::eligible();
        $valid = $fixture['eligible'];
        $forged = new EligibleReportPublication(
            $valid->candidate,
            $valid->candidateDocument,
            $valid->binding,
            $valid->evidence,
            $valid->proof,
            $valid->proofHash,
            $valid->candidateManifestHash,
            $valid->officialManifestHash,
            $valid->release,
            $valid->releaseArtifactBytes."\n",
        );
        $query = $this->createMock(Builder::class);
        $query->method('where')->willReturnSelf();
        $query->method('lockForUpdate')->willReturnSelf();
        $query->method('orderByDesc')->willReturnSelf();
        $query->method('first')->willReturn(null);
        $query->method('insert')->willReturn(true);
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('transaction')->willReturnCallback(
            static fn (callable $callback): mixed => $callback(),
        );
        $connection->method('select')->willReturn([]);
        $connection->method('table')->willReturn($query);
        $registry = new EloquentReportPublicationRegistry(
            $connection,
            $fixture['eligibility_service'],
            new ReportDefinitionFactory,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_publication_ineligible');

        $registry->promote($forged);
    }

    public function test_different_proof_for_an_active_code_conflicts_before_candidate_revalidation(): void
    {
        $active = ReportPublicationFixtureFactory::eligible();
        $different = ReportPublicationFixtureFactory::eligible('f');
        $candidate = $different['eligible'];
        $forged = new EligibleReportPublication(
            $candidate->candidate,
            $candidate->candidateDocument,
            $candidate->binding,
            $candidate->evidence,
            $candidate->proof,
            $candidate->proofHash,
            $candidate->candidateManifestHash,
            $candidate->officialManifestHash,
            $candidate->release,
            $candidate->releaseArtifactBytes."\n",
        );
        $query = $this->createMock(Builder::class);
        $query->method('where')->willReturnSelf();
        $query->method('lockForUpdate')->willReturnSelf();
        $query->method('first')->willReturn((object) $this->persistedRow($active['eligible']));
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('transaction')->willReturnCallback(
            static fn (callable $callback): mixed => $callback(),
        );
        $connection->method('select')->willReturn([]);
        $connection->method('table')->willReturn($query);
        $registry = new EloquentReportPublicationRegistry(
            $connection,
            $different['eligibility_service'],
            new ReportDefinitionFactory,
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('report_publication_promotion_conflict');

        $registry->promote($forged);
    }

    public function test_promotion_revalidates_evidence_reuse_against_the_actual_previous_record(): void
    {
        $fixture = ReportPublicationFixtureFactory::eligible();
        $previous = $fixture['eligible'];
        $payload = $previous->proof->payload();
        $releaseSha = str_repeat('b', 40);
        $ciPayload = [
            'checks' => array_fill_keys($payload['ci']['required_checks'], 'passed'),
            'commit_sha' => $releaseSha,
            'completed_at_utc' => '2026-08-01T03:00:00.000000Z',
            'run_id' => 'ci-2002',
        ];
        $ciEvidenceBytes = CanonicalJson::encode($ciPayload);
        $payload['ci'] = [
            'run_id' => $ciPayload['run_id'],
            'commit_sha' => $releaseSha,
            'suite_sha256' => hash('sha256', $ciEvidenceBytes),
            'completed_at_utc' => $ciPayload['completed_at_utc'],
            'required_checks' => $payload['ci']['required_checks'],
        ];
        $payload['release'] = [
            'git_sha' => $releaseSha,
            'created_at_utc' => '2026-08-01T04:00:00.000000Z',
            'approver_identity' => 'release-bot@most',
        ];
        $proof = ReportPublicationProof::fromArray($payload);
        $release = new ReportPublicationReleaseIdentity(
            $releaseSha,
            new DateTimeImmutable('2026-08-01T04:00:00.000000+00:00'),
            'release-bot@most',
        );
        $releaseArtifact = ReportPublicationReleaseArtifactTestFactory::issue(
            $proof,
            $previous->candidateManifestHash,
            $previous->officialManifestHash,
            $release,
            $ciPayload,
        );
        $publication = new EligibleReportPublication(
            $previous->candidate,
            $previous->candidateDocument,
            $previous->binding,
            $previous->evidence,
            $proof,
            $proof->digest(),
            $previous->candidateManifestHash,
            $previous->officialManifestHash,
            $release,
            $releaseArtifact,
        );
        $query = $this->createMock(Builder::class);
        $query->method('where')->willReturnSelf();
        $query->method('orderByDesc')->willReturnSelf();
        $query->method('lockForUpdate')->willReturnSelf();
        $query->method('first')->willReturnOnConsecutiveCalls(
            null,
            (object) $this->persistedRow($previous, 'disabled', '2026-08-01T02:30:00.000000Z', 'release_replaced'),
            null,
        );
        $query->method('insert')->willReturn(true);
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('transaction')->willReturnCallback(
            static fn (callable $callback): mixed => $callback(),
        );
        $connection->method('select')->willReturn([]);
        $connection->method('table')->willReturn($query);
        $registry = new EloquentReportPublicationRegistry(
            $connection,
            $fixture['eligibility_service'],
            new ReportDefinitionFactory,
        );

        $published = $registry->promote($publication);

        self::assertSame($releaseSha, $published->publicationIdentity?->releaseGitSha);
    }

    private function persistedRow(
        EligibleReportPublication $publication,
        string $status = 'published',
        ?string $disabledAt = null,
        ?string $disabledReason = null,
    ): array {
        $artifact = json_decode($publication->releaseArtifactBytes, true, 512, JSON_THROW_ON_ERROR);
        $proof = $publication->proof->payload();

        return [
            'id' => '01J00000000000000000000000',
            'code' => $publication->candidate->code,
            'status' => $status,
            'candidate_definition_json' => CanonicalJson::encode($publication->candidateDocument),
            'proof_json' => $publication->proof->canonicalBytes(),
            'proof_sha256' => $publication->proofHash->value,
            'candidate_manifest_sha256' => $publication->candidateManifestHash->value,
            'candidate_definition_sha256' => $publication->candidate->definitionHash->value,
            'official_manifest_sha256' => $publication->officialManifestHash->value,
            'binding_sha256' => $proof['binding_sha256'],
            'conformance_evidence_sha256' => $proof['conformance_evidence_sha256'],
            'release_artifact_json' => $publication->releaseArtifactBytes,
            'release_artifact_sha256' => hash('sha256', $publication->releaseArtifactBytes),
            'release_issuer' => $artifact['issuer'],
            'release_key_id' => $artifact['key_id'],
            'release_git_sha' => $publication->release->gitSha,
            'published_by' => $publication->release->approverIdentity,
            'published_at' => $publication->release->createdAtUtc(),
            'disabled_at' => $disabledAt,
            'disabled_reason' => $disabledReason,
        ];
    }
}
