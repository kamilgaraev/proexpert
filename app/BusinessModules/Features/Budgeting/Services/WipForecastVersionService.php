<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Services;

use App\BusinessModules\Features\Budgeting\Models\BudgetPeriod;
use App\BusinessModules\Features\Budgeting\Models\BudgetVersion;
use App\BusinessModules\Features\Budgeting\Models\WipForecastAdjustment;
use App\BusinessModules\Features\Budgeting\Models\WipForecastAuditEvent;
use App\BusinessModules\Features\Budgeting\Models\WipForecastVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use function trans_message;

final class WipForecastVersionService
{
    public const STATUS_EDITING = 'editing';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_REPLACED = 'replaced';
    public const STATUS_ARCHIVED = 'archived';

    public function __construct(
        private readonly WipForecastReportService $reportService,
        private readonly WipForecastPeriodGuard $periodGuard,
    ) {
    }

    public function index(array $input): array
    {
        $organizationId = $this->organizationId($input);
        $periodStart = $this->nullableString($input['period_start'] ?? null);
        $periodEnd = $this->nullableString($input['period_end'] ?? null);
        $budgetVersionUuid = $this->nullableString($input['budget_version_uuid'] ?? null);
        $scenarioUuid = $this->nullableString($input['scenario_uuid'] ?? null);
        $currency = $this->nullableString($input['currency'] ?? null);

        $versions = WipForecastVersion::query()
            ->with(['budgetVersion', 'scenario'])
            ->where('organization_id', $organizationId)
            ->when(isset($input['project_id']) && $input['project_id'] !== '', fn (Builder $query): Builder => $query->where('project_id', (int) $input['project_id']))
            ->when($periodStart !== null, fn (Builder $query): Builder => $query->whereDate('period_end', '>=', $periodStart))
            ->when($periodEnd !== null, fn (Builder $query): Builder => $query->whereDate('period_start', '<=', $periodEnd))
            ->when($currency !== null, fn (Builder $query): Builder => $query->where('currency', mb_strtoupper($currency)))
            ->when($budgetVersionUuid !== null, fn (Builder $query): Builder => $query->whereHas(
                'budgetVersion',
                fn (Builder $versionQuery): Builder => $versionQuery
                    ->where('organization_id', $organizationId)
                    ->where('uuid', $budgetVersionUuid)
            ))
            ->when($scenarioUuid !== null, fn (Builder $query): Builder => $query->whereHas(
                'scenario',
                fn (Builder $scenarioQuery): Builder => $scenarioQuery
                    ->where('organization_id', $organizationId)
                    ->where('uuid', $scenarioUuid)
            ))
            ->orderByDesc('version_number')
            ->limit(100)
            ->get();

        return [
            'items' => $versions->map(fn (WipForecastVersion $version): array => $this->versionToArray($version))->all(),
            'meta' => [
                'total' => $versions->count(),
            ],
        ];
    }

    public function create(array $input, User $user): array
    {
        $context = $this->reportService->resolveContext($input);
        $this->assertWritableBudgetVersion($context['budget_version'] ?? null);
        $report = $this->reportService->report([...$input, 'use_live_sources' => true], $user);
        $organizationId = $this->organizationId($input);
        $nextNumber = ((int) WipForecastVersion::query()
            ->where('organization_id', $organizationId)
            ->max('version_number')) + 1;

        return DB::transaction(function () use ($input, $user, $context, $report, $organizationId, $nextNumber): array {
            $active = WipForecastVersion::query()
                ->where('organization_id', $organizationId)
                ->where('status', self::STATUS_ACTIVE)
                ->when(isset($input['project_id']) && $input['project_id'] !== '', fn (Builder $query): Builder => $query->where('project_id', (int) $input['project_id']))
                ->orderByDesc('activated_at')
                ->lockForUpdate()
                ->first();

            $version = WipForecastVersion::create([
                'organization_id' => $organizationId,
                'project_id' => $input['project_id'] ?? null,
                'budget_version_id' => $context['budget_version'] instanceof BudgetVersion ? $context['budget_version']->id : null,
                'scenario_id' => $context['scenario']?->id,
                'previous_version_id' => $active?->id,
                'version_number' => $nextNumber,
                'name' => trim((string) $input['name']),
                'description' => $this->nullableString($input['description'] ?? null),
                'status' => self::STATUS_EDITING,
                'period_start' => $report['period']['from'],
                'period_end' => $report['period']['to'],
                'as_of_date' => $report['period']['as_of_date'],
                'currency' => $input['currency'] ?? null,
                'group_by' => $report['filters']['group_by'] ?? [],
                'source_snapshot_hash' => $report['meta']['source_snapshot_hash'] ?? null,
                'source_snapshot' => [
                    'filters' => $report['filters'],
                    'period' => $report['period'],
                    'generated_at' => $report['meta']['generated_at'] ?? null,
                ],
                'summary' => $report['summary'],
                'formulas' => $report['formulas'],
                'source_coverage' => $report['source_coverage'],
                'freshness' => $report['freshness'],
                'actions' => $report['actions'],
                'meta' => $report['meta'],
                'workflow_history' => [$this->workflowEvent(self::STATUS_EDITING, $user, trans_message('budgeting.wip_forecast.audit.created'))],
                'created_by' => $user->id,
            ]);

            $this->storeLines($version, $report);
            $this->audit($version, 'created', $user, trans_message('budgeting.wip_forecast.audit.created'), [], $version->only(['uuid', 'name', 'status']));

            return $this->showVersion($version);
        });
    }

    public function show(string $versionUuid, array $input): array
    {
        return $this->showVersion($this->findVersion($versionUuid, $this->organizationId($input)));
    }

    public function update(string $versionUuid, array $input, User $user): array
    {
        $version = $this->findVersion($versionUuid, $this->organizationId($input));
        $this->assertStatus($version, [self::STATUS_EDITING], 'budgeting.wip_forecast.errors.update_forbidden');
        $this->assertWritableForecastVersion($version);
        $oldValues = $version->only(['name', 'description', 'as_of_date', 'currency', 'group_by']);

        $version->fill(array_filter([
            'name' => isset($input['name']) ? trim((string) $input['name']) : null,
            'description' => array_key_exists('description', $input) ? $this->nullableString($input['description']) : null,
            'as_of_date' => isset($input['as_of_date']) ? CarbonImmutable::parse((string) $input['as_of_date'])->toDateString() : null,
            'currency' => isset($input['currency']) ? mb_strtoupper((string) $input['currency']) : null,
            'group_by' => $input['group_by'] ?? null,
        ], static fn (mixed $value): bool => $value !== null));
        $version->save();

        $this->audit($version, 'updated', $user, trans_message('budgeting.wip_forecast.audit.updated'), $oldValues, $version->only(['name', 'description', 'as_of_date', 'currency', 'group_by']));

        return $this->showVersion($version->refresh());
    }

    public function submit(string $versionUuid, array $input, User $user): array
    {
        $version = $this->findVersion($versionUuid, $this->organizationId($input));
        $this->assertStatus($version, [self::STATUS_EDITING], 'budgeting.wip_forecast.errors.submit_forbidden');
        $this->assertWritableForecastVersion($version);

        if ($version->lines()->count() === 0) {
            throw new DomainException(trans_message('budgeting.wip_forecast.errors.submit_empty'));
        }

        return $this->transition($version, self::STATUS_SUBMITTED, $user, $input['reason'] ?? null, 'submitted');
    }

    public function approve(string $versionUuid, array $input, User $user): array
    {
        $version = $this->findVersion($versionUuid, $this->organizationId($input));
        $this->assertStatus($version, [self::STATUS_SUBMITTED], 'budgeting.wip_forecast.errors.approve_forbidden');
        $this->assertWritableForecastVersion($version);

        return $this->transition($version, self::STATUS_APPROVED, $user, $input['reason'] ?? null, 'approved');
    }

    public function activate(string $versionUuid, array $input, User $user): array
    {
        $version = $this->findVersion($versionUuid, $this->organizationId($input));
        $this->assertStatus($version, [self::STATUS_APPROVED], 'budgeting.wip_forecast.errors.activate_forbidden');
        $this->assertWritableForecastVersion($version);

        return DB::transaction(function () use ($version, $user, $input): array {
            $active = WipForecastVersion::query()
                ->where('organization_id', $version->organization_id)
                ->where('status', self::STATUS_ACTIVE)
                ->when($version->project_id !== null, fn (Builder $query): Builder => $query->where('project_id', $version->project_id))
                ->lockForUpdate()
                ->first();

            if ($active instanceof WipForecastVersion) {
                $active->status = self::STATUS_REPLACED;
                $active->save();
                $this->audit($active, 'replaced', $user, trans_message('budgeting.wip_forecast.audit.replaced'), [], ['replaced_by' => $version->uuid]);
            }

            if ($version->previous_version_id === null && $active instanceof WipForecastVersion) {
                $version->previous_version_id = $active->id;
            }

            return $this->transition($version, self::STATUS_ACTIVE, $user, $input['reason'] ?? null, 'activated');
        });
    }

    public function addAdjustment(string $versionUuid, array $input, User $user): array
    {
        $version = $this->findVersion($versionUuid, $this->organizationId($input));
        $this->assertStatus($version, [self::STATUS_EDITING, self::STATUS_APPROVED, self::STATUS_ACTIVE], 'budgeting.wip_forecast.errors.adjustment_forbidden');
        $this->assertWritableForecastVersion($version);

        $reason = trim((string) ($input['reason'] ?? ''));
        if ($reason === '') {
            throw new DomainException(trans_message('budgeting.wip_forecast.errors.adjustment_reason_required'));
        }

        if (isset($input['period']) && is_string($input['period']) && trim($input['period']) !== '') {
            $this->periodGuard->assertWritablePeriod($input['period'], $this->lockedPeriodsForVersion($version));
        }

        $adjustment = WipForecastAdjustment::create([
            'forecast_version_id' => $version->id,
            'organization_id' => $version->organization_id,
            'scope' => $input['scope'] ?? 'line',
            'scope_id' => $input['scope_id'] ?? null,
            'project_id' => $input['project_id'] ?? $version->project_id,
            'stage_id' => $input['stage_id'] ?? null,
            'contract_id' => $input['contract_id'] ?? null,
            'estimate_item_id' => $input['estimate_item_id'] ?? null,
            'period' => $input['period'] ?? null,
            'adjustment_type' => $input['adjustment_type'] ?? 'cost',
            'formula_component' => $input['formula_component'],
            'amount' => $input['amount'],
            'percent' => $input['percent'] ?? null,
            'currency' => isset($input['currency']) ? mb_strtoupper((string) $input['currency']) : ($version->currency ?? 'RUB'),
            'reason' => $reason,
            'owner_user_id' => $user->id,
            'status' => 'approved',
            'valid_from' => $input['valid_from'] ?? null,
            'valid_until' => $input['valid_until'] ?? null,
            'affects_formulas' => [$input['formula_component']],
            'source_snapshot_hash' => $version->source_snapshot_hash,
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);

        $this->audit($version, 'adjustment_added', $user, $reason, [], $adjustment->only(['uuid', 'formula_component', 'amount', 'currency', 'period']));

        return [
            'adjustment' => $this->adjustmentToArray($adjustment),
            'version' => $this->versionToArray($version->refresh()),
        ];
    }

    public function auditEvents(string $versionUuid, array $input): array
    {
        $version = $this->findVersion($versionUuid, $this->organizationId($input));
        $events = $version->auditEvents()
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        return [
            'items' => $events->map(static fn (WipForecastAuditEvent $event): array => [
                'id' => $event->uuid,
                'event_type' => $event->event_type,
                'reason' => $event->reason,
                'actor_user_id' => $event->actor_user_id,
                'old_values' => $event->old_values ?? [],
                'new_values' => $event->new_values ?? [],
                'source_snapshot_hash' => $event->source_snapshot_hash,
                'created_at' => $event->created_at?->toIso8601String(),
            ])->all(),
            'meta' => [
                'total' => $events->count(),
            ],
        ];
    }

    private function transition(WipForecastVersion $version, string $status, User $user, mixed $reason, string $eventType): array
    {
        $oldStatus = (string) $version->status;
        $field = match ($status) {
            self::STATUS_SUBMITTED => 'submitted',
            self::STATUS_APPROVED => 'approved',
            self::STATUS_ACTIVE => 'activated',
            default => null,
        };

        $history = is_array($version->workflow_history) ? $version->workflow_history : [];
        $history[] = $this->workflowEvent($status, $user, $this->nullableString($reason));

        $version->status = $status;
        $version->workflow_history = $history;
        if ($field !== null) {
            $version->{$field . '_by'} = $user->id;
            $version->{$field . '_at'} = now();
        }
        $version->save();

        $this->audit($version, $eventType, $user, $this->nullableString($reason), ['status' => $oldStatus], ['status' => $status]);

        return $this->showVersion($version->refresh());
    }

    private function storeLines(WipForecastVersion $version, array $report): void
    {
        foreach ($report['rows'] as $row) {
            $metrics = $row['metrics'];
            $group = $row['group'];

            $version->lines()->create([
                'organization_id' => $version->organization_id,
                'project_id' => $this->dimensionId($row['project'] ?? null, $group['project'] ?? null),
                'stage_id' => $this->dimensionId($row['stage'] ?? null, $group['stage'] ?? null),
                'contract_id' => $this->dimensionId($row['contract'] ?? null, $group['contract'] ?? null),
                'estimate_item_id' => $this->dimensionId($row['estimate_item'] ?? null, $group['estimate_item'] ?? null),
                'period' => is_string($group['period'] ?? null) ? $group['period'] : null,
                'currency' => $row['currency'],
                'bac' => $metrics['bac'] ?? 0,
                'percent_complete' => $metrics['percent_complete'] ?? null,
                'ev' => $metrics['ev'] ?? 0,
                'pv' => $metrics['pv'] ?? 0,
                'ac' => $metrics['ac'] ?? 0,
                'wip_total' => $metrics['wip'] ?? 0,
                'ctc' => $metrics['ctc'] ?? 0,
                'etc' => $metrics['etc'] ?? 0,
                'ftc' => $metrics['ftc'] ?? 0,
                'eac' => $metrics['eac'] ?? 0,
                'forecast_revenue_at_completion' => $metrics['forecast_revenue'] ?? 0,
                'forecast_gross_margin' => $metrics['forecast_gross_margin'] ?? 0,
                'forecast_margin_percent' => $metrics['forecast_margin_percent'] ?? null,
                'cpi' => $metrics['cpi'] ?? null,
                'spi' => $metrics['spi'] ?? null,
                'quality_status' => $row['quality_status'] ?? 'actual',
                'group_values' => $group,
                'dimensions' => [
                    'project' => $row['project'] ?? null,
                    'stage' => $row['stage'] ?? null,
                    'contract' => $row['contract'] ?? null,
                    'estimate_item' => $row['estimate_item'] ?? null,
                ],
                'problem_flags' => $row['problem_flags'] ?? [],
                'risk_flags' => $row['risk_flags'] ?? [],
                'source_row_refs' => $row['source_row_refs'] ?? [],
                'formula_components' => [
                    'approved_act_value' => $metrics['approved_act_value'] ?? 0,
                    'cash_only_payments_excluded' => $metrics['cash_only_payments_excluded'] ?? 0,
                    'manual_adjustments' => $metrics['manual_adjustments'] ?? 0,
                    'source_types' => $row['source_types'] ?? [],
                    'source_rows_count' => $row['source_rows_count'] ?? 0,
                ],
                'comparison' => $row['comparison'] ?? [],
                'source_snapshot_hash' => $version->source_snapshot_hash,
            ]);
        }
    }

    private function showVersion(WipForecastVersion $version): array
    {
        $version->loadMissing(['budgetVersion', 'scenario', 'adjustments', 'assumptions']);

        return [
            'version' => $this->versionToArray($version),
            'adjustments' => $version->adjustments->map(fn (WipForecastAdjustment $adjustment): array => $this->adjustmentToArray($adjustment))->all(),
            'assumptions' => $version->assumptions->map(static fn ($assumption): array => [
                'id' => $assumption->uuid,
                'title' => $assumption->title,
                'description' => $assumption->description,
                'status' => $assumption->status,
            ])->all(),
        ];
    }

    private function versionToArray(WipForecastVersion $version): array
    {
        return [
            'id' => $version->uuid,
            'name' => $version->name,
            'description' => $version->description,
            'status' => $version->status,
            'version_number' => $version->version_number,
            'project_id' => $version->project_id,
            'period_start' => $version->period_start?->toDateString(),
            'period_end' => $version->period_end?->toDateString(),
            'as_of_date' => $version->as_of_date?->toDateString(),
            'currency' => $version->currency,
            'source_snapshot_hash' => $version->source_snapshot_hash,
            'summary' => $version->summary ?? [],
            'freshness' => $version->freshness ?? [],
            'budget_version' => $version->budgetVersion instanceof BudgetVersion ? [
                'id' => $version->budgetVersion->uuid,
                'name' => $version->budgetVersion->name,
                'status' => $version->budgetVersion->status,
            ] : null,
            'scenario' => $version->scenario === null ? null : [
                'id' => $version->scenario->uuid,
                'code' => $version->scenario->code,
                'name' => $version->scenario->name,
            ],
            'submitted_at' => $version->submitted_at?->toIso8601String(),
            'approved_at' => $version->approved_at?->toIso8601String(),
            'activated_at' => $version->activated_at?->toIso8601String(),
            'created_at' => $version->created_at?->toIso8601String(),
        ];
    }

    private function adjustmentToArray(WipForecastAdjustment $adjustment): array
    {
        return [
            'id' => $adjustment->uuid,
            'scope' => $adjustment->scope,
            'scope_id' => $adjustment->scope_id,
            'project_id' => $adjustment->project_id,
            'stage_id' => $adjustment->stage_id,
            'contract_id' => $adjustment->contract_id,
            'estimate_item_id' => $adjustment->estimate_item_id,
            'period' => $adjustment->period,
            'adjustment_type' => $adjustment->adjustment_type,
            'formula_component' => $adjustment->formula_component,
            'amount' => (float) $adjustment->amount,
            'percent' => $adjustment->percent === null ? null : (float) $adjustment->percent,
            'currency' => $adjustment->currency,
            'reason' => $adjustment->reason,
            'status' => $adjustment->status,
            'approved_at' => $adjustment->approved_at?->toIso8601String(),
        ];
    }

    private function findVersion(string $uuid, int $organizationId): WipForecastVersion
    {
        $version = WipForecastVersion::query()
            ->with(['budgetVersion.period', 'scenario'])
            ->where('organization_id', $organizationId)
            ->where('uuid', $uuid)
            ->first();

        if (!$version instanceof WipForecastVersion) {
            throw new DomainException(trans_message('budgeting.wip_forecast.errors.version_not_found'));
        }

        return $version;
    }

    private function assertWritableBudgetVersion(mixed $version): void
    {
        if (!$version instanceof BudgetVersion) {
            return;
        }

        $version->loadMissing('period');
        if ($version->period instanceof BudgetPeriod) {
            $this->periodGuard->assertVersionIsWritablePeriod($version->period);
        }
    }

    private function assertWritableForecastVersion(WipForecastVersion $version): void
    {
        $version->loadMissing('budgetVersion.period');
        if ($version->budgetVersion instanceof BudgetVersion && $version->budgetVersion->period instanceof BudgetPeriod) {
            $this->periodGuard->assertVersionIsWritablePeriod($version->budgetVersion->period);
        }
    }

    /**
     * @return list<string>
     */
    private function lockedPeriodsForVersion(WipForecastVersion $version): array
    {
        $version->loadMissing('budgetVersion.period');
        $period = $version->budgetVersion?->period;

        if (!$period instanceof BudgetPeriod) {
            return [];
        }

        $lockedStatuses = [
            BudgetPeriodClosureService::STATUS_CLOSING,
            BudgetPeriodClosureService::STATUS_CLOSED,
            BudgetPeriodClosureService::STATUS_SOFT_CLOSED,
            BudgetPeriodClosureService::STATUS_ARCHIVED,
        ];

        if (!in_array((string) $period->status, $lockedStatuses, true)) {
            return [];
        }

        $months = [];
        $cursor = CarbonImmutable::parse((string) $period->starts_at)->startOfMonth();
        $end = CarbonImmutable::parse((string) $period->ends_at)->startOfMonth();

        while ($cursor->lte($end)) {
            $months[] = $cursor->format('Y-m');
            $cursor = $cursor->addMonth();
        }

        return $months;
    }

    private function assertStatus(WipForecastVersion $version, array $allowedStatuses, string $messageKey): void
    {
        if (!in_array((string) $version->status, $allowedStatuses, true)) {
            throw new DomainException(trans_message($messageKey));
        }
    }

    private function audit(WipForecastVersion $version, string $eventType, User $user, ?string $reason, array $oldValues, array $newValues): void
    {
        $version->auditEvents()->create([
            'organization_id' => $version->organization_id,
            'event_type' => $eventType,
            'actor_user_id' => $user->id,
            'reason' => $reason,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'source_snapshot_hash' => $version->source_snapshot_hash,
            'created_at' => now(),
        ]);
    }

    private function workflowEvent(string $status, User $user, ?string $reason): array
    {
        return [
            'status' => $status,
            'actor_user_id' => $user->id,
            'reason' => $reason,
            'at' => now()->toIso8601String(),
        ];
    }

    private function dimensionId(?array $dimension, mixed $fallback): ?int
    {
        $value = $dimension['id'] ?? $fallback;

        return is_numeric($value) ? (int) $value : null;
    }

    private function organizationId(array $input): int
    {
        $organizationId = (int) ($input['organization_id'] ?? 0);

        if ($organizationId <= 0) {
            throw new DomainException(trans_message('budgeting.organization_context_missing'));
        }

        return $organizationId;
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
