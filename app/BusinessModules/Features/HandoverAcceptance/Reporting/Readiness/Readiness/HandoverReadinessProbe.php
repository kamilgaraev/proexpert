<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\Readiness;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSourceReadinessProbe;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceReadiness;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSourceReadinessStatus;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class HandoverReadinessProbe implements ReportSourceReadinessProbe
{
    public function supports(ReportDefinition $definition): bool
    {
        return $definition->code === 'handover_readiness'
            && $definition->formulaVersion === 'handover.v1'
            && $definition->sourceSchemaVersion === 'handover-readiness.v1';
    }

    public function reportCodes(): array
    {
        return ['handover_readiness'];
    }

    public function inspect(
        ReportExecutionContext $context,
        ReportQuery $query,
    ): ReportSourceReadiness {
        $scopes = DB::table('acceptance_scopes')
            ->where('organization_id', $context->scope->organizationId)
            ->where('created_at', '<=', $query->asOf)
            ->when(
                $query->scope->projectIds !== [],
                fn ($builder) => $builder->whereIn('project_id', $query->scope->projectIds),
            )
            ->orderBy('id')
            ->get(['id', 'project_id', 'updated_at']);
        $scopeIds = $scopes->pluck('id')->all();
        $gates = DB::table('handover_gate_versions')
            ->where('organization_id', $context->scope->organizationId)
            ->whereIn('acceptance_scope_id', $scopeIds)
            ->where('effective_from', '<=', $query->asOf)
            ->where(static function ($builder) use ($query): void {
                $builder->whereNull('effective_to')->orWhere('effective_to', '>', $query->asOf);
            })
            ->orderByDesc('gate_version')
            ->orderByDesc('id')
            ->get()
            ->unique(static fn (object $gate): string => $gate->acceptance_scope_id.':'.$gate->gate_code)
            ->values();
        $events = DB::table('handover_evidence_events')
            ->where('organization_id', $context->scope->organizationId)
            ->whereIn('acceptance_scope_id', $scopeIds)
            ->where('occurred_at', '<=', $query->asOf)
            ->orderBy('id')
            ->get();
        $unknownScopeIds = DB::table('acceptance_checklist_items as item')
            ->join('acceptance_checklists as checklist', 'checklist.id', '=', 'item.acceptance_checklist_id')
            ->whereIn('checklist.acceptance_scope_id', $scopeIds)
            ->where('item.status', '<>', 'pending')
            ->where(static function ($builder): void {
                $builder->whereNull('item.code')->orWhereNull('item.reviewed_at');
            })
            ->pluck('checklist.acceptance_scope_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->all();
        $unknownScopeIds = array_values(array_unique([
            ...$unknownScopeIds,
            ...DB::table('acceptance_findings')
                ->whereIn('acceptance_scope_id', $scopeIds)
                ->where('status', 'resolved')
                ->whereNull('resolved_at')
                ->pluck('acceptance_scope_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all(),
            ...DB::table('handover_package_documents as document')
                ->join('handover_packages as package', 'package.id', '=', 'document.handover_package_id')
                ->whereIn('package.acceptance_scope_id', $scopeIds)
                ->where('document.status', 'approved')
                ->whereNull('document.approved_at')
                ->pluck('package.acceptance_scope_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all(),
        ]));
        $requiredBlockers = ['change', 'constraint', 'quality_defect', 'rfi'];
        $invalidGateIds = $gates->filter(static function (object $gate) use ($requiredBlockers): bool {
            $checklists = is_string($gate->required_checklist_codes)
                ? json_decode($gate->required_checklist_codes, true)
                : $gate->required_checklist_codes;
            $documents = is_string($gate->required_document_codes)
                ? json_decode($gate->required_document_codes, true)
                : $gate->required_document_codes;
            $blockers = is_string($gate->hard_blocker_source_types)
                ? json_decode($gate->hard_blocker_source_types, true)
                : $gate->hard_blocker_source_types;
            $empty = $checklists === [] && $documents === [];

            return ! is_array($checklists)
                || ! array_is_list($checklists)
                || ! is_array($documents)
                || ! array_is_list($documents)
                || ! is_array($blockers)
                || ! array_is_list($blockers)
                || array_diff($requiredBlockers, $blockers) !== []
                || (bool) $gate->explicitly_empty_requirements !== $empty
                || preg_match('/^[a-f0-9]{64}$/D', (string) $gate->source_hash) !== 1;
        })->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $gatesByScope = $gates->groupBy(
            static fn (object $gate): int => (int) $gate->acceptance_scope_id,
        );
        $missingGateScopeIds = $scopes->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->reject(static fn (int $id): bool => $gatesByScope->has($id))
            ->values()
            ->all();
        $eligible = $gates->count() + count($missingGateScopeIds);
        $unknownGates = $gates->filter(static fn (object $gate): bool => in_array((int) $gate->acceptance_scope_id, $unknownScopeIds, true)
            || in_array((int) $gate->id, $invalidGateIds, true))->count();
        $unknown = $unknownGates + count(array_intersect($missingGateScopeIds, $unknownScopeIds));
        $projected = $gates->count() - $unknownGates;
        $gap = max(0, $eligible - $projected - $unknown);
        $inputHash = hash('sha256', CanonicalJson::encode([
            'scope_rows' => $scopes->map(static fn (object $scope): array => [
                'id' => (int) $scope->id,
                'project_id' => (int) $scope->project_id,
                'updated_at' => (string) $scope->updated_at,
            ])->all(),
        ]));
        $outputHash = hash('sha256', CanonicalJson::encode([
            'event_hashes' => $events->pluck('evidence_hash')->all(),
            'gate_hashes' => $gates->pluck('source_hash')->all(),
        ]));
        $ready = $gap === 0 && $unknown === 0;

        return new ReportSourceReadiness(
            $ready ? ReportSourceReadinessStatus::READY : ReportSourceReadinessStatus::PARTIAL,
            $eligible,
            $projected,
            $gap,
            $unknown,
            'handover_event_'.(string) ($events->max('id') ?? 0),
            $inputHash,
            $outputHash,
            $ready ? CarbonImmutable::now('UTC') : null,
        );
    }
}
