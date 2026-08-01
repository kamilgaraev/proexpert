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
                ->lockForUpdate()
                ->first();
            $previousRow = $existing ?? $this->connection->table('report_publications')
                ->where('code', $publication->candidate->code)
                ->orderByDesc('published_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();
            $previous = $previousRow === null ? null : $this->record((array) $previousRow);
            $publication = $this->eligibility->evaluate(
                $publication->candidate,
                $publication->candidateDocument,
                $publication->binding,
                $publication->evidence,
                $publication->proof,
                $publication->candidateManifestHash,
                $publication->officialManifestHash,
                $publication->release,
                $publication->ciArtifactBytes,
                $previous,
            )->publication();
            if ($existing !== null) {
                $record = $this->record((array) $existing);
                if (! hash_equals($record->identity->proofHash->value, $publication->proofHash->value)
                    || ! hash_equals(
                        (string) $existing->official_manifest_sha256,
                        $publication->officialManifestHash->value,
                    )) {
                    throw new LogicException('report_publication_promotion_conflict');
                }

                return $this->published($record);
            }

            $id = (string) Str::ulid();
            $proof = $publication->proof->payload();
            $publishedAt = new DateTimeImmutable($proof['release']['created_at_utc']);
            $this->connection->table('report_publications')->insert([
                'id' => $id,
                'code' => $publication->candidate->code,
                'status' => ReportPublicationStatus::PUBLISHED->value,
                'candidate_definition_json' => CanonicalJson::encode($publication->candidateDocument),
                'proof_json' => $publication->proof->canonicalBytes(),
                'proof_sha256' => $publication->proofHash->value,
                'candidate_manifest_sha256' => $publication->candidateManifestHash->value,
                'candidate_definition_sha256' => $publication->candidate->definitionHash->value,
                'official_manifest_sha256' => $publication->officialManifestHash->value,
                'binding_sha256' => $proof['binding_sha256'],
                'conformance_evidence_sha256' => $proof['conformance_evidence_sha256'],
                'contract_version' => $proof['versions']['contract'],
                'source_schema_version' => $proof['versions']['source_schema'],
                'formula_version' => $proof['versions']['formula'],
                'renderer_version' => $proof['versions']['renderer'],
                'release_git_sha' => $publication->release->gitSha,
                'published_by' => $publication->release->approverIdentity,
                'published_at' => $publishedAt,
                'disabled_at' => null,
                'disabled_reason' => null,
            ]);
            $feature = $this->connection->table('report_publication_features')
                ->where('code', $publication->candidate->code)
                ->lockForUpdate()
                ->first();
            $featureValues = [
                'code' => $publication->candidate->code,
                'publication_id' => $id,
                'proof_sha256' => $publication->proofHash->value,
                'mode' => 'off',
                'canary_organization_ids' => '[]',
                'canary_user_ids' => '[]',
                'updated_at' => $publishedAt,
            ];
            if ($feature === null) {
                $this->connection->table('report_publication_features')->insert($featureValues);
            } else {
                if ($feature->mode !== 'disabled') {
                    throw new LogicException('report_publication_feature_rebind_conflict');
                }
                $this->connection->table('report_publication_features')
                    ->where('code', $publication->candidate->code)
                    ->update($featureValues);
            }

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

        $this->connection->transaction(function () use ($publicationId, $reason, $actorIdentity): void {
            $row = $this->connection->table('report_publications')
                ->where('id', $publicationId)
                ->lockForUpdate()
                ->first();
            if ($row === null || $row->status !== ReportPublicationStatus::PUBLISHED->value) {
                throw new LogicException('report_publication_not_active');
            }
            $disabledAt = new DateTimeImmutable('now');
            $this->connection->table('report_publications')->where('id', $publicationId)->update([
                'status' => ReportPublicationStatus::DISABLED->value,
                'disabled_at' => $disabledAt,
                'disabled_reason' => $reason,
                'disabled_by' => $actorIdentity,
            ]);
        });
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
}
