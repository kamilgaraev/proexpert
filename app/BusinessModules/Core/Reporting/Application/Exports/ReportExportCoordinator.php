<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Exports;

use App\BusinessModules\Core\Reporting\Application\Access\ReportExecutionContextFactory;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportScopeAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionClock;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExportStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunStore;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorization;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorizationTarget;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportRunExportSource;
use App\BusinessModules\Core\Reporting\Application\Input\CreateReportExportData;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionBindingAssembler;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
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
        private ReportDefinitionBindingAssembler $bindings,
        private CurrentReportScopeAuthorizer $authorizer,
        private ReportExecutionContextFactory $contexts,
        private ReportExportRendererRegistry $renderers,
        private ReportExecutionClock $clock,
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
        $currentContext = $this->authorize($context, $currentSource, $published, $columns);
        $this->renderers->resolve($published, $data);

        return $this->exports->createOrReuse($currentContext, $currentSource, $data, $key);
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
        $binding = $this->bindings->assemble($this->definitions)->get($definition->code);
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

    private function authorize(
        ReportExecutionContext $context,
        ReportRunExportSource $source,
        PublishedReportDefinition $published,
        array $columns,
    ): ReportExecutionContext {
        if ($context->scope->canonicalIdentity() !== $source->query->scope->canonicalIdentity()) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN);
        }

        $current = $this->authorizeOperation($context, $source, $published, ReportOperation::EXPORT);
        $classification = $published->definition->outputClassification;
        if ($classification->requiresSensitiveForColumns($columns)) {
            $current = $this->authorizeOperation($context, $source, $published, ReportOperation::VIEW_SENSITIVE);
        }
        if ($classification->requiresAuditForColumns($columns)) {
            $current = $this->authorizeOperation($context, $source, $published, ReportOperation::VIEW_AUDIT);
        }

        return $this->contexts->fromCurrentAuthorization($current);
    }

    private function authorizeOperation(
        ReportExecutionContext $context,
        ReportRunExportSource $source,
        PublishedReportDefinition $published,
        ReportOperation $operation,
    ): CurrentReportAuthorization {
        return $this->authorizer->authorizeExact(
            $context->actor->id,
            $source->query->scope,
            new CurrentReportAuthorizationTarget($published->definition, $operation, $source->snapshot),
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
