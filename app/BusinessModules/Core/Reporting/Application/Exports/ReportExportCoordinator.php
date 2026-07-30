<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Exports;

use App\BusinessModules\Core\Reporting\Application\Access\ReportAuthorizationFence;
use App\BusinessModules\Core\Reporting\Application\Access\ReportExecutionContextFactory;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportAuthorizationSubjectReader;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportScopeAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionClock;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExportStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunStore;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportRunExportSource;
use App\BusinessModules\Core\Reporting\Application\Input\CreateReportExportData;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBindingMap;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExport;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportRunStatus;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\IdempotencyKey;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\ReportExportRendererRegistry;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use DateTimeZone;

final readonly class ReportExportCoordinator
{
    public function __construct(
        private ReportRunStore $runs,
        private ReportExportStore $exports,
        private ReportDefinitionRegistry $definitions,
        private ReportDefinitionBindingMap $bindings,
        private CurrentReportScopeAuthorizer $authorizer,
        private ReportExecutionContextFactory $contexts,
        private ReportExportRendererRegistry $renderers,
        private ReportExecutionClock $clock,
        private ReportAuthorizationSubjectReader $subjects,
    ) {}

    public function create(
        ReportExecutionContext $context,
        ReportRunExportSource $source,
        CreateReportExportData $data,
        IdempotencyKey $key,
    ): ReportExport {
        $this->assertReady($source);
        $fingerprint = $this->sourceFingerprint($source);
        $currentSource = $this->runs->exportSource($context, $source->run->id);
        $this->assertReady($currentSource);
        if (! hash_equals($fingerprint, $this->sourceFingerprint($currentSource))) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
        }

        $published = $this->publishedFor($currentSource);
        $columns = $published->definition->validatedSelectedColumnIds($data->columns);
        $fence = $this->authorizationFence($context, $currentSource, $published, $columns);
        $fence->assertCurrent($context);
        $this->renderers->resolve($published, $data);

        return $this->exports->createOrReuse($context, $currentSource, $data, $key, $fence);
    }

    private function assertReady(ReportRunExportSource $source): void
    {
        if ($source->run->status === ReportRunStatus::EXPIRED || $source->run->expiresAt <= $this->clock->now()) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SNAPSHOT_EXPIRED);
        }
        if ($source->run->status !== ReportRunStatus::READY) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SNAPSHOT_NOT_READY);
        }
    }

    private function publishedFor(ReportRunExportSource $source): PublishedReportDefinition
    {
        $published = $this->definitions->published($source->query->definition->code);
        $definition = $published->definition;
        $binding = $this->bindings->get($definition->code);
        if (! hash_equals($definition->definitionHash->value, $source->query->definition->definitionHash->value)
            || ! hash_equals($definition->definitionHash->value, $source->run->definitionHash->value)
            || ! hash_equals($definition->contractVersion, $source->contractVersion)
            || ! hash_equals($definition->formulaVersion, $source->formulaVersion)
            || ! hash_equals($definition->sourceSchemaVersion, $source->sourceSchemaVersion)
            || ! hash_equals($definition->rendererVersion, $source->rendererVersion)
            || ! hash_equals($binding->definitionHash->value, $definition->definitionHash->value)
            || ! hash_equals($binding->contractVersion, $definition->contractVersion)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
        }

        return $published;
    }

    private function authorizationFence(
        ReportExecutionContext $context,
        ReportRunExportSource $source,
        PublishedReportDefinition $published,
        array $columns,
    ): ReportAuthorizationFence {
        $subject = $this->subjects->run($source->run->id);
        ReportAuthorizationFence::assertExactScope($context, $subject);
        if ($subject->snapshot === null
            || ! hash_equals($subject->definition->definitionHash->value, $published->definitionHash->value)
            || ! hash_equals($subject->snapshot->sourceHash->value, $source->snapshot->sourceHash->value)
            || ! hash_equals($subject->snapshot->id, $source->snapshot->id)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
        }

        $operations = [ReportOperation::EXPORT];
        $classification = $published->definition->outputClassification;
        if ($classification->requiresSensitiveForColumns($columns)) {
            $operations[] = ReportOperation::VIEW_SENSITIVE;
        }
        if ($classification->requiresAuditForColumns($columns)) {
            $operations[] = ReportOperation::VIEW_AUDIT;
        }

        return new ReportAuthorizationFence(
            $subject,
            $operations,
            $this->authorizer,
            $this->contexts,
        );
    }

    private function sourceFingerprint(ReportRunExportSource $source): string
    {
        $snapshot = $source->snapshot;
        $seal = $snapshot->seal;

        return hash('sha256', CanonicalJson::encode([
            'run' => [
                'id' => $source->run->id,
                'definition_hash' => $source->run->definitionHash->value,
                'query_hash' => $source->run->queryHash->value,
                'source_hash' => $source->run->sourceHash?->value,
                'expires_at' => $this->utc($source->run->expiresAt),
            ],
            'query' => $source->query->canonicalJson,
            'result_hash' => $source->resultHash->value,
            'snapshot' => [
                'kind' => $snapshot->kind,
                'id' => $snapshot->id,
                'scope' => $snapshot->scope->canonicalIdentity(),
                'definition_hash' => $snapshot->definitionHash->value,
                'formula_version' => $snapshot->formulaVersion,
                'source_hash' => $snapshot->sourceHash->value,
                'generated_at' => $this->utc($snapshot->generatedAt),
                'stale_at' => $snapshot->staleAt === null ? null : $this->utc($snapshot->staleAt),
                'watermarks' => $snapshot->watermarks,
                'classification' => $snapshot->classification->value,
                'seal' => $seal === null ? null : [
                    'key_id' => $seal->keyId,
                    'algorithm' => $seal->algorithm,
                    'sealed_payload_hash' => $seal->sealedPayloadHash->value,
                    'signature' => $seal->signature,
                    'sealed_at' => $this->utc($seal->sealedAt),
                ],
            ],
            'classification' => [
                'data' => $source->dataClassification->value,
                'sensitive' => $source->outputClassification->sensitiveColumnIds,
                'audit' => $source->outputClassification->auditColumnIds,
                'totals_sensitive' => $source->outputClassification->totalsSensitive,
                'totals_audit' => $source->outputClassification->totalsAudit,
                'provenance_audit' => $source->outputClassification->provenanceAudit,
            ],
            'versions' => [
                $source->contractVersion,
                $source->formulaVersion,
                $source->sourceSchemaVersion,
                $source->rendererVersion,
            ],
        ]));
    }

    private function utc(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
