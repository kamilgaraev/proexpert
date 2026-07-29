<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Persistence;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunAsyncContextSeedReader;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportAsyncContextSeed;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScopedResource;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportRunRecord;
use DateTimeZone;
use Throwable;

final readonly class EloquentReportRunAsyncContextSeedReader implements ReportRunAsyncContextSeedReader
{
    public function __construct(private ReportDefinitionRegistry $definitions) {}

    public function forRun(string $runId): ReportAsyncContextSeed
    {
        try {
            $record = ReportRunRecord::query()
                ->whereKey($runId)
                ->first([
                    'id',
                    'organization_id',
                    'requester_actor_id',
                    'report_code',
                    'definition_hash',
                    'contract_version',
                    'formula_version',
                    'source_schema_version',
                    'renderer_version',
                    'scope_holding_organization_ids',
                    'scope_project_ids',
                    'scope_resources',
                    'scope_timezone',
                    'correlation_lineage_id',
                ]);
            if (! $record instanceof ReportRunRecord) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
            }

            $definition = $this->definitions->published((string) $record->report_code)->payload();
            foreach ([
                [$definition->definitionHash->value, $record->definition_hash],
                [$definition->contractVersion, $record->contract_version],
                [$definition->formulaVersion, $record->formula_version],
                [$definition->sourceSchemaVersion, $record->source_schema_version],
                [$definition->rendererVersion, $record->renderer_version],
            ] as [$expected, $persisted]) {
                if (! is_string($persisted) || ! hash_equals($expected, $persisted)) {
                    throw new \InvalidArgumentException('report_async_definition_identity_mismatch');
                }
            }

            $scope = new ReportScope(
                (int) $record->organization_id,
                $this->positiveIntegerList($record->scope_holding_organization_ids),
                $this->positiveIntegerList($record->scope_project_ids),
                $this->typedResources($record->scope_resources),
                new DateTimeZone((string) $record->scope_timezone),
            );

            return new ReportAsyncContextSeed(
                'run',
                (string) $record->id,
                (int) $record->organization_id,
                (int) $record->requester_actor_id,
                $scope,
                $definition,
                is_string($record->correlation_lineage_id) ? $record->correlation_lineage_id : null,
            );
        } catch (ReportContractException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR, previous: $exception);
        }
    }

    private function positiveIntegerList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new \InvalidArgumentException('report_async_scope_invalid');
        }
        foreach ($value as $id) {
            if (! is_int($id) || $id < 1) {
                throw new \InvalidArgumentException('report_async_scope_invalid');
            }
        }

        return $value;
    }

    private function typedResources(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new \InvalidArgumentException('report_async_scope_invalid');
        }
        $resources = [];
        $inputCanonical = [];
        foreach ($value as $item) {
            $keys = is_array($item) ? array_keys($item) : [];
            sort($keys, SORT_STRING);
            if (! is_array($item)
                || array_is_list($item)
                || $keys !== ['id', 'kind', 'project_id']
                || ! is_string($item['kind'])
                || ! is_int($item['id'])
                || (! is_int($item['project_id']) && $item['project_id'] !== null)) {
                throw new \InvalidArgumentException('report_async_scope_invalid');
            }
            $resources[] = new ReportScopedResource($item['kind'], $item['id'], $item['project_id']);
            $inputCanonical[] = [
                'kind' => $item['kind'],
                'id' => $item['id'],
                'project_id' => $item['project_id'],
            ];
        }
        $canonical = array_map(
            static fn (ReportScopedResource $resource): array => $resource->canonicalIdentity(),
            (new ReportScope(1, [1], [], $resources, new DateTimeZone('UTC')))->resources,
        );
        if ($canonical !== $inputCanonical) {
            throw new \InvalidArgumentException('report_async_scope_invalid');
        }

        return $resources;
    }
}
