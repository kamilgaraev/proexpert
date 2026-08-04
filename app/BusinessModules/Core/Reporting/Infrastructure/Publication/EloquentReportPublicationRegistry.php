<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Publication;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportPublicationRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationIdentity;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationProof;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationRecord;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportPublicationReadiness;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportPublicationStatus;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use LogicException;

final class EloquentReportPublicationRegistry implements ReportPublicationRegistry
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly ReportDefinitionFactory $definitions,
    ) {}

    public function current(string $code): ?PublishedReportDefinition
    {
        $this->assertCode($code);
        $row = $this->connection->table('public.report_publications')
            ->where('code', $code)
            ->where('status', ReportPublicationStatus::PUBLISHED->value)
            ->first();

        return $row === null ? null : $this->published($this->record((array) $row));
    }

    public function currentRecord(string $code): ?ReportPublicationRecord
    {
        $this->assertCode($code);
        $row = $this->connection->table('public.report_publications')
            ->where('code', $code)
            ->where('status', ReportPublicationStatus::PUBLISHED->value)
            ->first();

        return $row === null ? null : $this->record((array) $row);
    }

    public function publishedCodes(): array
    {
        return $this->connection->table('public.report_publications')
            ->where('status', ReportPublicationStatus::PUBLISHED->value)
            ->orderBy('code')
            ->pluck('code')
            ->map(static fn (mixed $code): string => (string) $code)
            ->all();
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
            null,
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
