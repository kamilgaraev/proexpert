<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\Services;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\Services\CompletedWork\Reporting\AcceptedProduction\DTO\AcceptedProductionHistoryBoundary;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Models\ProductionAcceptanceHistoryCheckpoint;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

final readonly class AcceptedProductionHistoryBoundaryResolver
{
    public function resolve(ReportScope $scope, ReportQuery $query): AcceptedProductionHistoryBoundary
    {
        if ($scope->canonicalIdentity() !== $query->scope->canonicalIdentity()) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN);
        }

        $checkpoint = ProductionAcceptanceHistoryCheckpoint::query()
            ->where('organization_id', $scope->organizationId)
            ->first();
        if (! $checkpoint instanceof ProductionAcceptanceHistoryCheckpoint) {
            $this->unavailable();
        }

        $boundary = new AcceptedProductionHistoryBoundary(
            DateTimeImmutable::createFromInterface($checkpoint->completed_at),
            (int) $checkpoint->performance_act_watermark_id,
            (int) $checkpoint->owner_version_watermark_id,
            (int) $checkpoint->owner_member_watermark_id,
            (int) $checkpoint->event_watermark_id,
            (int) $checkpoint->backfill_ledger_watermark_id,
            trim((string) $checkpoint->source_hash),
        );
        $this->assertQueryCovered($boundary, $scope, $query);
        $this->assertCheckpointAndAppendOnlySourcesIntact($scope->organizationId, $checkpoint);

        return $boundary;
    }

    private function assertQueryCovered(
        AcceptedProductionHistoryBoundary $boundary,
        ReportScope $scope,
        ReportQuery $query,
    ): void {
        $values = $query->filters->values;
        $from = $values['period_from'] ?? null;
        $to = $values['period_to'] ?? null;
        $coverageStart = $boundary->coverageStartDay($scope->timezone);
        $asOfDay = $query->asOf->setTimezone($scope->timezone)->format('Y-m-d');
        if (! is_string($from)
            || ! is_string($to)
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $from) !== 1
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $to) !== 1
            || DateTimeImmutable::createFromFormat('!Y-m-d', $from)?->format('Y-m-d') !== $from
            || DateTimeImmutable::createFromFormat('!Y-m-d', $to)?->format('Y-m-d') !== $to
            || $from < $coverageStart
            || $from > $to
            || $to > $asOfDay
            || $query->asOf < $boundary->completedAt
        ) {
            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_FILTER_RANGE_INVALID,
            );
        }
    }

    private function assertCheckpointAndAppendOnlySourcesIntact(
        int $organizationId,
        ProductionAcceptanceHistoryCheckpoint $checkpoint,
    ): void {
        $actual = DB::selectOne(<<<'SQL'
WITH checkpoint AS (
    SELECT *
    FROM production_acceptance_history_checkpoints
    WHERE organization_id = :organization_id
), owner_sources AS (
    SELECT
        COUNT(owner.id)::bigint AS owner_version_count,
        encode(sha256(convert_to(COALESCE(string_agg(
            encode(sha256(convert_to(jsonb_build_array(owner.id, owner.source_hash)::text, 'UTF8')), 'hex'),
            '' ORDER BY owner.id
        ) FILTER (WHERE owner.id IS NOT NULL), ''), 'UTF8')), 'hex') AS owner_version_set_hash
    FROM checkpoint
    LEFT JOIN production_acceptance_owner_versions AS owner
      ON owner.organization_id = checkpoint.organization_id
     AND owner.id <= checkpoint.owner_version_watermark_id
    GROUP BY checkpoint.id
), member_sources AS (
    SELECT
        COUNT(member.id)::bigint AS owner_member_count,
        encode(sha256(convert_to(COALESCE(string_agg(
            encode(sha256(convert_to(jsonb_build_object(
                'id', member.id,
                'owner_version_id', member.owner_version_id,
                'owner_organization_id', owner.organization_id,
                'owner_project_id', owner.project_id,
                'owner_performance_act_id', owner.performance_act_id,
                'member_organization_id', member.organization_id,
                'member_project_id', member.project_id,
                'member_performance_act_id', member.performance_act_id,
                'source_line_type', member.source_line_type,
                'source_line_id', member.source_line_id,
                'work_id', member.work_id,
                'contractor_id', member.contractor_id,
                'unit_code', member.unit_code,
                'zone', member.zone
            )::text, 'UTF8')), 'hex'),
            '' ORDER BY member.id
        ) FILTER (WHERE member.id IS NOT NULL), ''), 'UTF8')), 'hex') AS owner_member_set_hash
    FROM checkpoint
    LEFT JOIN production_acceptance_owner_versions AS owner
      ON owner.organization_id = checkpoint.organization_id
    LEFT JOIN production_acceptance_owner_members AS member
      ON member.owner_version_id = owner.id
     AND member.id <= checkpoint.owner_member_watermark_id
    GROUP BY checkpoint.id
), event_sources AS (
    SELECT
        COUNT(event.id)::bigint AS event_count,
        encode(sha256(convert_to(COALESCE(string_agg(
            encode(sha256(convert_to(jsonb_build_array(event.id, event.source_hash)::text, 'UTF8')), 'hex'),
            '' ORDER BY event.id
        ) FILTER (WHERE event.id IS NOT NULL), ''), 'UTF8')), 'hex') AS event_set_hash
    FROM checkpoint
    LEFT JOIN production_acceptance_events AS event
      ON event.organization_id = checkpoint.organization_id
     AND event.id <= checkpoint.event_watermark_id
    GROUP BY checkpoint.id
), ledger_sources AS (
    SELECT
        COUNT(ledger.id) FILTER (WHERE ledger.status = 'unprovable')::bigint AS unprovable_legacy_count,
        encode(sha256(convert_to(COALESCE(string_agg(
            encode(sha256(convert_to(jsonb_build_array(ledger.id, ledger.source_hash)::text, 'UTF8')), 'hex'),
            '' ORDER BY ledger.id
        ) FILTER (WHERE ledger.id IS NOT NULL), ''), 'UTF8')), 'hex') AS backfill_ledger_set_hash
    FROM checkpoint
    LEFT JOIN production_acceptance_backfill_ledger AS ledger
      ON ledger.organization_id = checkpoint.organization_id
     AND ledger.id <= checkpoint.backfill_ledger_watermark_id
    GROUP BY checkpoint.id
)
SELECT
    owner_sources.*,
    member_sources.*,
    event_sources.*,
    ledger_sources.*,
    encode(sha256(convert_to(jsonb_build_object(
        'organization_id', checkpoint.organization_id,
        'completed_at', to_char(checkpoint.completed_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"'),
        'excluded_legacy_act_count', checkpoint.excluded_legacy_act_count,
        'performance_act_watermark_id', checkpoint.performance_act_watermark_id,
        'legacy_act_set_hash', checkpoint.legacy_act_set_hash,
        'owner_version_count', checkpoint.owner_version_count,
        'owner_version_watermark_id', checkpoint.owner_version_watermark_id,
        'owner_version_set_hash', checkpoint.owner_version_set_hash,
        'owner_member_count', checkpoint.owner_member_count,
        'owner_member_watermark_id', checkpoint.owner_member_watermark_id,
        'owner_member_set_hash', checkpoint.owner_member_set_hash,
        'event_count', checkpoint.event_count,
        'event_watermark_id', checkpoint.event_watermark_id,
        'event_set_hash', checkpoint.event_set_hash,
        'unprovable_legacy_count', checkpoint.unprovable_legacy_count,
        'backfill_ledger_watermark_id', checkpoint.backfill_ledger_watermark_id,
        'backfill_ledger_set_hash', checkpoint.backfill_ledger_set_hash
    )::text, 'UTF8')), 'hex') AS checkpoint_source_hash
FROM checkpoint
CROSS JOIN owner_sources
CROSS JOIN member_sources
CROSS JOIN event_sources
CROSS JOIN ledger_sources
SQL, ['organization_id' => $organizationId]);

        if (! is_object($actual)
            || (int) $actual->owner_version_count !== (int) $checkpoint->owner_version_count
            || (int) $actual->owner_member_count !== (int) $checkpoint->owner_member_count
            || (int) $actual->event_count !== (int) $checkpoint->event_count
            || (int) $actual->unprovable_legacy_count !== (int) $checkpoint->unprovable_legacy_count
            || ! hash_equals(trim((string) $checkpoint->owner_version_set_hash), trim((string) $actual->owner_version_set_hash))
            || ! hash_equals(trim((string) $checkpoint->owner_member_set_hash), trim((string) $actual->owner_member_set_hash))
            || ! hash_equals(trim((string) $checkpoint->event_set_hash), trim((string) $actual->event_set_hash))
            || ! hash_equals(trim((string) $checkpoint->backfill_ledger_set_hash), trim((string) $actual->backfill_ledger_set_hash))
            || ! hash_equals(trim((string) $checkpoint->source_hash), trim((string) $actual->checkpoint_source_hash))
        ) {
            $this->unavailable();
        }
    }

    private function unavailable(): never
    {
        throw ReportContractException::fromCode(
            ReportErrorCode::REPORT_SOURCE_UNAVAILABLE,
        );
    }
}
