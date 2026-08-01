<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Publication;

use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationEligibilityService;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportPublicationRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\EligibleReportPublication;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationIdentity;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationProof;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationRecord;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportPublicationReadiness;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportPublicationStatus;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

final class EloquentReportPublicationRegistry implements ReportPublicationRegistry
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly ReportPublicationEligibilityService $eligibility,
        private readonly ReportDefinitionFactory $definitions,
    ) {}

    public function current(string $code): ?PublishedReportDefinition
    {
        $this->assertCode($code);
        $row = $this->connection->table('report_publications')
            ->where('code', $code)
            ->where('status', ReportPublicationStatus::PUBLISHED->value)
            ->first();

        return $row === null ? null : $this->published($this->record((array) $row));
    }

    public function promote(EligibleReportPublication $publication): PublishedReportDefinition
    {
        return $this->connection->transaction(function () use ($publication): PublishedReportDefinition {
            $this->connection->select(
                'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
                ['report-publication:'.$publication->candidate->code],
            );
            $existing = $this->connection->table('report_publications')
                ->where('code', $publication->candidate->code)
                ->where('status', ReportPublicationStatus::PUBLISHED->value)
                ->first();
            $existingRecord = $existing === null ? null : $this->record((array) $existing);
            if ($existingRecord !== null
                && (! hash_equals($existingRecord->identity->proofHash->value, $publication->proofHash->value)
                    || ! hash_equals(
                        (string) $existing->official_manifest_sha256,
                        $publication->officialManifestHash->value,
                    ))) {
                throw new LogicException('report_publication_promotion_conflict');
            }
            $previous = $existingRecord;
            if ($previous === null) {
                $previousRow = $this->connection->table('report_publications')
                    ->where('code', $publication->candidate->code)
                    ->orderByDesc('published_at')
                    ->orderByDesc('id')
                    ->first();
                $previous = $previousRow === null ? null : $this->record((array) $previousRow);
            }
            $publication = $this->eligibility->evaluate(
                $publication->candidate,
                $publication->candidateDocument,
                $publication->binding,
                $publication->evidence,
                $publication->proof,
                $publication->candidateManifestHash,
                $publication->officialManifestHash,
                $publication->release,
                $publication->releaseArtifactBytes,
                $previous,
            )->publication();
            if ($existingRecord !== null) {
                return $this->published($existingRecord);
            }

            $id = (string) Str::ulid();
            $proof = $publication->proof->payload();
            $releaseArtifact = $this->eligibility->verifyReleaseArtifact($publication->releaseArtifactBytes);
            $releaseArtifactPayload = $releaseArtifact->payload();
            $publishedAt = new DateTimeImmutable($proof['release']['created_at_utc']);
            $this->connection->select(<<<'SQL'
                SELECT public.report_publication_promote(
                    ?, ?, CAST(? AS jsonb), CAST(? AS jsonb), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, CAST(? AS timestamptz)
                )
                SQL, [
                $id,
                $publication->candidate->code,
                CanonicalJson::encode($publication->candidateDocument),
                $publication->proof->canonicalBytes(),
                $publication->proofHash->value,
                $publication->candidateManifestHash->value,
                $publication->candidate->definitionHash->value,
                $publication->officialManifestHash->value,
                $proof['binding_sha256'],
                $proof['conformance_evidence_sha256'],
                $proof['versions']['contract'],
                $proof['versions']['source_schema'],
                $proof['versions']['formula'],
                $proof['versions']['renderer'],
                $publication->release->gitSha,
                $releaseArtifact->canonicalBytes(),
                hash('sha256', $releaseArtifact->canonicalBytes()),
                $releaseArtifactPayload['issuer'],
                $releaseArtifactPayload['key_id'],
                $publication->release->approverIdentity,
                $publication->release->createdAtUtc(),
            ]);

            return $this->published(new ReportPublicationRecord(
                new ReportPublicationIdentity(
                    $id,
                    $publication->candidate->code,
                    $publication->proofHash,
                    $publication->release->gitSha,
                ),
                ReportPublicationStatus::PUBLISHED,
                $publication->proof,
                $publication->candidateDocument,
                $publishedAt,
                null,
                null,
            ));
        });
    }

    public function disable(string $publicationId, string $reason, string $actorIdentity): void
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $publicationId) !== 1
            || preg_match('/^[a-z][a-z0-9_.-]{2,127}$/D', $reason) !== 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9@._:-]{2,127}$/D', $actorIdentity) !== 1) {
            throw new InvalidArgumentException('report_publication_disable_input_invalid');
        }

        try {
            $this->connection->select(
                'SELECT public.report_publication_disable(?, ?, ?)',
                [$publicationId, $reason, $actorIdentity],
            );
        } catch (QueryException $exception) {
            if ($this->hasSqlState($exception, 'P0002')) {
                throw new LogicException('report_publication_not_active', 0, $exception);
            }

            throw $exception;
        }
    }

    public function history(string $code): iterable
    {
        $this->assertCode($code);
        foreach ($this->connection->table('report_publications')
            ->where('code', $code)
            ->orderBy('published_at')
            ->orderBy('id')
            ->cursor() as $row) {
            yield $this->record((array) $row);
        }
    }

    private function published(ReportPublicationRecord $record): PublishedReportDefinition
    {
        $definition = $this->definition($record);

        return new PublishedReportDefinition(new ReportDefinition(
            $definition->code,
            $definition->definitionHash,
            $definition->contractVersion,
            $definition->formulaVersion,
            $definition->sourceSchemaVersion,
            $definition->rendererVersion,
            $definition->filters,
            $definition->columns,
            $definition->sorts,
            $definition->formats,
            $definition->permissionPolicy,
            $definition->snapshotClassification,
            $definition->outputClassification,
            ReportPublicationReadiness::PUBLISHED,
            $definition->supportsSubscriptions,
            $definition->sourceModule,
            $definition->coreAccessMode,
        ), $record->identity);
    }

    private function record(array $row): ReportPublicationRecord
    {
        $proofData = $this->decodeMap($row['proof_json'] ?? null);
        $proof = ReportPublicationProof::fromArray($proofData);
        $proofHash = new Sha256Hash((string) ($row['proof_sha256'] ?? ''));
        if (! hash_equals($proofHash->value, $proof->digest()->value)) {
            throw new LogicException('report_publication_persisted_proof_drift');
        }
        $releaseArtifactBytes = $row['release_artifact_json'] ?? null;
        if (! is_string($releaseArtifactBytes)
            || ! hash_equals((string) ($row['release_artifact_sha256'] ?? ''), hash('sha256', $releaseArtifactBytes))) {
            throw new LogicException('report_publication_persisted_release_artifact_drift');
        }
        $releaseArtifact = $this->eligibility->verifyReleaseArtifact($releaseArtifactBytes);
        $releasePayload = $releaseArtifact->payload();
        $releaseSubject = $releasePayload['subject'];
        if (! hash_equals($releaseSubject['proof_sha256'], $proofHash->value)
            || ! hash_equals($releaseSubject['code'], (string) $row['code'])
            || ! hash_equals($releaseSubject['candidate_manifest_sha256'], (string) $row['candidate_manifest_sha256'])
            || ! hash_equals($releaseSubject['candidate_definition_sha256'], (string) $row['candidate_definition_sha256'])
            || ! hash_equals($releaseSubject['official_manifest_sha256'], (string) $row['official_manifest_sha256'])
            || ! hash_equals($releaseSubject['binding_sha256'], (string) $row['binding_sha256'])
            || ! hash_equals($releaseSubject['conformance_evidence_sha256'], (string) $row['conformance_evidence_sha256'])
            || ! hash_equals($releaseSubject['release_git_sha'], (string) $row['release_git_sha'])
            || ! hash_equals($releaseSubject['approver_identity'], (string) $row['published_by'])
            || ! hash_equals($releasePayload['issuer'], (string) $row['release_issuer'])
            || ! hash_equals($releasePayload['key_id'], (string) $row['release_key_id'])) {
            throw new LogicException('report_publication_persisted_release_artifact_drift');
        }

        $record = new ReportPublicationRecord(
            new ReportPublicationIdentity(
                (string) $row['id'],
                (string) $row['code'],
                $proofHash,
                (string) $row['release_git_sha'],
            ),
            ReportPublicationStatus::from((string) $row['status']),
            $proof,
            $this->decodeMap($row['candidate_definition_json'] ?? null),
            new DateTimeImmutable((string) $row['published_at']),
            $row['disabled_at'] === null ? null : new DateTimeImmutable((string) $row['disabled_at']),
            $row['disabled_reason'] === null ? null : (string) $row['disabled_reason'],
            $releaseArtifactBytes,
        );
        $this->definition($record);

        return $record;
    }

    private function definition(ReportPublicationRecord $record): ReportDefinition
    {
        $definition = $this->definitions->fromManifest($record->candidateDocument);
        if (! hash_equals($definition->code, $record->identity->code)
            || ! hash_equals(
                $definition->definitionHash->value,
                $record->proof->payload()['candidate_definition_sha256'],
            )) {
            throw new LogicException('report_publication_candidate_drift');
        }

        return $definition;
    }

    private function decodeMap(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : $value;
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new LogicException('report_publication_persisted_json_invalid');
        }

        return $decoded;
    }

    private function assertCode(string $code): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{2,63}$/D', $code) !== 1) {
            throw new InvalidArgumentException('report_publication_code_invalid');
        }
    }

    private function hasSqlState(QueryException $exception, string $sqlState): bool
    {
        return ($exception->errorInfo[0] ?? null) === $sqlState
            || (string) $exception->getCode() === $sqlState;
    }
}
