<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Persistence;

use App\BusinessModules\Core\Reporting\Application\Access\ReportAuthorizationSubject;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportAuthorizationSubjectReader;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchAggregate;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScopedResource;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotSeal;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportExportStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportRunStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportExportRecord;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportRunRecord;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

final class EloquentReportAuthorizationSubjectReader implements ReportAuthorizationSubjectReader
{
    public function __construct(private readonly ReportRunHydrator $runHydrator) {}

    public function run(string $runId): ReportAuthorizationSubject
    {
        try {
            $record = ReportRunRecord::query()->whereKey($runId)->first();
            if (! $record instanceof ReportRunRecord) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
            }
            $query = $this->runHydrator->query($record);
            $status = ReportRunStatus::from($this->string($record->status));
            $snapshot = in_array($status, [ReportRunStatus::READY, ReportRunStatus::EXPIRED], true)
                ? $this->snapshot($record, $query->scope)
                : null;

            return new ReportAuthorizationSubject(ReportDispatchAggregate::RUN, $this->string($record->id), $query->definition, $query->scope, $snapshot, null, null);
        } catch (ReportContractException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR, [], $exception);
        }
    }

    public function export(string $exportId): ReportAuthorizationSubject
    {
        try {
            $export = ReportExportRecord::query()->whereKey($exportId)->first();
            if (! $export instanceof ReportExportRecord) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
            }
            $run = ReportRunRecord::query()->whereKey($export->run_id)->where('organization_id', $export->organization_id)->first();
            if (! $run instanceof ReportRunRecord) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
            }
            $query = $this->runHydrator->query($run);
            $scope = $this->scope($export);
            if ($scope->canonicalIdentity() !== $query->scope->canonicalIdentity()
                || ! hash_equals($this->string($export->definition_hash), $query->definition->definitionHash->value)
                || ! $this->matchesParentIdentity($export, $run)) {
                throw new \InvalidArgumentException('report_export_parent_identity_mismatch');
            }
            $snapshot = $this->snapshot($export, $scope);
            $status = ReportExportStatus::from($this->string($export->status));
            $artifactIdentityHash = in_array($status, [ReportExportStatus::READY, ReportExportStatus::EXPIRED], true)
                ? new Sha256Hash($this->string($export->artifact_checksum))
                : null;

            return new ReportAuthorizationSubject(
                ReportDispatchAggregate::EXPORT,
                $this->string($export->id),
                $query->definition,
                $scope,
                $snapshot,
                $this->string($export->run_id),
                $artifactIdentityHash,
                ReportExportAuthorizationIdentity::fromRecord($export),
            );
        } catch (ReportContractException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR, [], $exception);
        }
    }

    private function snapshot(ReportRunRecord|ReportExportRecord $record, ReportScope $scope): ReportSnapshotRef
    {
        $classification = ReportSnapshotClassification::from($this->string($record->snapshot_classification));
        $values = [$record->snapshot_seal_key_id, $record->snapshot_seal_algorithm, $record->snapshot_sealed_payload_hash, $record->snapshot_seal_signature, $record->snapshot_sealed_at];
        $present = array_map(static fn (mixed $value): bool => $value !== null, $values);
        if (in_array(true, $present, true) && in_array(false, $present, true)) {
            throw new \InvalidArgumentException('report_snapshot_seal_incomplete');
        }
        $seal = ! in_array(true, $present, true) ? null : new ReportSnapshotSeal($this->string($record->snapshot_seal_key_id), $this->string($record->snapshot_seal_algorithm), new Sha256Hash($this->string($record->snapshot_sealed_payload_hash)), $this->string($record->snapshot_seal_signature), $this->instant($record->snapshot_sealed_at));

        return new ReportSnapshotRef($this->string($record->snapshot_kind), $this->string($record->snapshot_id), $scope, new Sha256Hash($this->string($record->definition_hash)), $this->string($record->formula_version), new Sha256Hash($this->string($record->source_hash)), $this->instant($record->snapshot_generated_at), $record->snapshot_stale_at === null ? null : $this->instant($record->snapshot_stale_at), $this->array($record->snapshot_watermarks), $classification, $seal);
    }

    private function scope(ReportExportRecord $record): ReportScope
    {
        $resources = [];
        foreach ($this->array($record->scope_resources) as $item) {
            if (! is_array($item) || array_keys($item) !== ['kind', 'id', 'project_id'] || ! is_string($item['kind']) || ! is_int($item['id']) || (! is_int($item['project_id']) && $item['project_id'] !== null)) {
                throw new \InvalidArgumentException('report_export_scope_invalid');
            }
            $resources[] = new ReportScopedResource($item['kind'], $item['id'], $item['project_id']);
        }
        $scope = new ReportScope((int) $record->organization_id, array_map('intval', $this->array($record->scope_holding_organization_ids)), array_map('intval', $this->array($record->scope_project_ids)), $resources, new DateTimeZone($this->string($record->scope_timezone)));
        if (array_map(static fn (ReportScopedResource $resource): array => $resource->canonicalIdentity(), $scope->resources) !== $this->array($record->scope_resources)) {
            throw new \InvalidArgumentException('report_export_scope_invalid');
        }

        return $scope;
    }

    private function matchesParentIdentity(ReportExportRecord $export, ReportRunRecord $run): bool
    {
        foreach ($this->parentIdentityPairs($export, $run) as [$exportValue, $runValue]) {
            if (! $this->identityValuesMatch($exportValue, $runValue)) {
                return false;
            }
        }

        return true;
    }

    private function parentIdentityPairs(ReportExportRecord $export, ReportRunRecord $run): array
    {
        return [
            [$export->report_code, $run->report_code],
            [$export->definition_hash, $run->definition_hash],
            [$export->query_hash, $run->query_hash],
            [$export->source_hash, $run->source_hash],
            [$export->result_hash, $run->result_hash],
            [$export->snapshot_kind, $run->snapshot_kind],
            [$export->snapshot_id, $run->snapshot_id],
            [$export->snapshot_generated_at, $run->snapshot_generated_at],
            [$export->snapshot_stale_at, $run->snapshot_stale_at],
            [$export->snapshot_watermarks, $run->snapshot_watermarks],
            [$export->snapshot_classification, $run->snapshot_classification],
            [$export->snapshot_seal_key_id, $run->snapshot_seal_key_id],
            [$export->snapshot_seal_algorithm, $run->snapshot_seal_algorithm],
            [$export->snapshot_sealed_payload_hash, $run->snapshot_sealed_payload_hash],
            [$export->snapshot_seal_signature, $run->snapshot_seal_signature],
            [$export->snapshot_sealed_at, $run->snapshot_sealed_at],
            [$export->data_classification, $run->data_classification],
            [$export->sensitive_column_ids, $run->sensitive_column_ids],
            [$export->audit_column_ids, $run->audit_column_ids],
            [$export->totals_sensitive, $run->totals_sensitive],
            [$export->totals_audit, $run->totals_audit],
            [$export->provenance_audit, $run->provenance_audit],
            [$export->contract_version, $run->contract_version],
            [$export->formula_version, $run->formula_version],
            [$export->source_schema_version, $run->source_schema_version],
            [$export->renderer_version, $run->renderer_version],
        ];
    }

    private function identityValuesMatch(mixed $left, mixed $right): bool
    {
        if (is_string($left) && is_string($right)) {
            return hash_equals($left, $right);
        }
        if ($left instanceof DateTimeInterface && $right instanceof DateTimeInterface) {
            return $left->format('U.u') === $right->format('U.u');
        }

        return $left === $right;
    }

    private function string(mixed $value): string { if (! is_string($value) || $value === '') { throw new \InvalidArgumentException('report_persistence_string_invalid'); } return $value; }
    private function array(mixed $value): array { if (! is_array($value) || ! array_is_list($value)) { throw new \InvalidArgumentException('report_persistence_array_invalid'); } return $value; }
    private function instant(mixed $value): DateTimeImmutable { if ($value instanceof DateTimeInterface) { return DateTimeImmutable::createFromInterface($value); } if (is_string($value) && $value !== '') { return new DateTimeImmutable($value); } throw new \InvalidArgumentException('report_persistence_instant_invalid'); }
}
