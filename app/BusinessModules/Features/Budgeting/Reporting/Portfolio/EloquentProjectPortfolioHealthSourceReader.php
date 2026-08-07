<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\Portfolio;

use App\BusinessModules\Core\Payments\DTOs\PaymentCalendarSourceFilters;
use App\BusinessModules\Core\Payments\DTOs\PaymentCalendarItem;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Budgeting\DTOs\EpmDataMartScope;
use App\BusinessModules\Features\Budgeting\Reporting\BudgetPlanFactCandidateContract;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectMarginCandidateContract;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\Models\ProjectFinanceSnapshot;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\WipCompletionForecastCandidateContract;
use App\BusinessModules\Features\Budgeting\Models\EpmDataMartSnapshot;
use App\BusinessModules\Features\Budgeting\Models\ResponsibilityCenter;
use App\BusinessModules\Features\Budgeting\Services\EpmDataMartPayloadProjector;
use App\Enums\CurrencyCode;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

final readonly class EloquentProjectPortfolioHealthSourceReader implements ProjectPortfolioHealthSourceReader
{
    public function __construct(
        private PortfolioLiquidityAsOfSource $liquidity,
        private ProjectPortfolioHealthOwnerSourcePolicy $ownerSourcePolicy,
        private ProjectPortfolioHealthOwnerCandidateSelector $ownerCandidateSelector,
        private ReportDefinitionRegistry $definitions,
    ) {}

    public function read(ReportExecutionContext $context, ReportQuery $query): array
    {
        $components = [];
        $gaps = [];
        $cohorts = [];
        $projectIds = $this->projectIds($context, $query);
        $period = $this->period($query);
        $scopeHash = hash('sha256', CanonicalJson::encode($context->scope->canonicalIdentity()));
        if ($projectIds === null) {
            return [
                'components' => [],
                'gaps' => array_map(
                    static fn (string $kind): array => ['code' => 'owner_source_scope_incompatible', 'kind' => $kind],
                    ProjectPortfolioHealthSourceTupleAssembler::REQUIRED_KINDS,
                ),
            ];
        }
        $ownerRequest = [
            'scope_project_ids' => $context->scope->projectIds,
            'project_ids' => $projectIds,
            'currencies' => $query->filters->values['currencies'] ?? [],
            'responsibility_center_ids' => $query->filters->values['responsibility_center_ids'] ?? [],
            'counterparty_ids' => $query->filters->values['counterparty_ids'] ?? [],
        ];
        $responsibilityCenterIds = $this->filterIds($ownerRequest['responsibility_center_ids']);
        $counterpartyIds = $this->filterIds($ownerRequest['counterparty_ids']);
        $currencies = $this->filterCurrencies($ownerRequest['currencies']);
        $ownerRequest['responsibility_center_uuids'] = $responsibilityCenterIds === null
            ? []
            : ($this->responsibilityCenterUuids(
                $context->scope->organizationId,
                $responsibilityCenterIds,
            ) ?? []);
        foreach ($this->ownerContracts() as $kind => $contract) {
            $evaluated = [];
            $snapshots = ProjectFinanceSnapshot::query()
                ->where('organization_id', $context->scope->organizationId)
                ->where('report_code', $kind)
                ->where('as_of', $query->asOf)
                ->where('scope_hash', $scopeHash)
                ->whereDate('period_from', $period['from'])
                ->whereDate('period_to', $period['to'])
                ->where('formula_version', $contract['formula'])
                ->where('source_schema_version', $contract['schema'])
                ->where('quality_status', 'complete')
                ->where('coverage_numerator', '>', 0)
                ->whereColumn('coverage_numerator', 'coverage_denominator')
                ->whereColumn('coverage_numerator', 'row_count')
                ->where('generated_at', '<=', $query->asOf)
                ->whereNotNull('stale_at')->where('stale_at', '>', $query->asOf)
                ->orderBy('id')
                ->get();
            foreach ($snapshots as $snapshot) {
                $snapshotIdentityKey = $this->ownerIdentityKey(
                    (string) $snapshot->definition_hash,
                    (string) $snapshot->query_hash,
                );
                $sourceHash = (string) $snapshot->source_hash;
                $version = trim((string) $snapshot->formula_version).'|'.trim((string) $snapshot->source_schema_version);
                $snapshotRows = $snapshot->rows()
                    ->get(['organization_id', 'report_code', 'project_id', 'currency']);
                $parent = EpmDataMartSnapshot::query()
                    ->where('uuid', (string) $snapshot->source_snapshot_id)
                    ->where('organization_id', $context->scope->organizationId)
                    ->first();
                if (! $parent instanceof EpmDataMartSnapshot) {
                    $evaluated[] = [
                        'classification' => 'unresolved',
                        'identity_key' => $snapshotIdentityKey,
                    ];
                    continue;
                }
                $sourceBoundary = [
                    'organization_id' => $snapshot->organization_id,
                    'report_code' => $snapshot->report_code,
                    'scope_hash' => $snapshot->scope_hash,
                    'definition_hash' => $snapshot->definition_hash,
                    'query_hash' => $snapshot->query_hash,
                    'source_hash' => $snapshot->source_hash,
                    'source_snapshot_kind' => $snapshot->source_snapshot_kind,
                    'source_snapshot_id' => $snapshot->source_snapshot_id,
                    'source_snapshot_hash' => $snapshot->source_snapshot_hash,
                    'formula' => $snapshot->formula_version,
                    'schema' => $snapshot->source_schema_version,
                    'quality_status' => $snapshot->quality_status,
                    'coverage_numerator' => $snapshot->coverage_numerator,
                    'coverage_denominator' => $snapshot->coverage_denominator,
                    'row_count' => $snapshot->row_count,
                    'rows_count' => $snapshotRows->count(),
                    'row_organization_ids' => $snapshotRows->pluck('organization_id')->all(),
                    'row_report_codes' => $snapshotRows->pluck('report_code')->all(),
                    'period_from' => $snapshot->period_from?->format('Y-m-d'),
                    'period_to' => $snapshot->period_to?->format('Y-m-d'),
                    'as_of' => $snapshot->as_of?->format(DateTimeInterface::ATOM),
                    'generated_at' => $snapshot->generated_at?->format(DateTimeInterface::ATOM),
                    'stale_at' => $snapshot->stale_at?->format(DateTimeInterface::ATOM),
                    'parent_uuid' => $parent->uuid,
                    'parent_organization_id' => $parent->organization_id,
                    'parent_report_scope' => $parent->report_scope,
                    'parent_scope_hash_valid' => $this->parentScopeMatches($parent),
                    'parent_status' => $parent->status,
                    'parent_superseded_at' => $parent->superseded_at?->format(DateTimeInterface::ATOM),
                    'parent_formula' => $parent->formula_version,
                    'parent_source_hash' => $parent->source_hash,
                    'parent_generated_at' => $parent->generated_at?->format(DateTimeInterface::ATOM),
                    'parent_stale_at' => $parent->stale_at?->format(DateTimeInterface::ATOM),
                    'parent_period_from' => $parent->period_start?->format('Y-m-d'),
                    'parent_period_to' => $parent->period_end?->format('Y-m-d'),
                    'parent_as_of_date' => $parent->as_of_date?->format('Y-m-d'),
                    'project_id' => $parent->project_id,
                    'currency' => $parent->currency,
                    'filters' => $parent->filters,
                    'row_project_ids' => $snapshotRows->pluck('project_id')->all(),
                    'row_currencies' => $snapshotRows->pluck('currency')->all(),
                    'budget_version_id' => $snapshot->budget_version_id,
                    'forecast_version_id' => $snapshot->forecast_version_id,
                    'closure_hash' => $snapshot->closure_hash,
                ];
                $requestBoundary = [
                    ...$ownerRequest,
                    'organization_id' => $context->scope->organizationId,
                    'kind' => $kind,
                    'epm_scope' => $this->epmScope($kind),
                    'scope_hash' => $scopeHash,
                    'period_from' => $period['from'],
                    'period_to' => $period['to'],
                    'as_of' => $query->asOf->format(DateTimeInterface::ATOM),
                    'as_of_date' => $query->asOf->format('Y-m-d'),
                    'formula' => $contract['formula'],
                    'schema' => $contract['schema'],
                    'parent_formula' => EpmDataMartPayloadProjector::FORMULA_VERSION,
                ];
                $identity = $this->ownerQueryIdentity(
                    $context,
                    $query,
                    $kind,
                    $requestBoundary,
                    $sourceBoundary,
                );
                if ($identity === null) {
                    $evaluated[] = [
                        'classification' => 'unresolved',
                        'identity_key' => $snapshotIdentityKey,
                    ];
                    continue;
                }
                $expectedIdentityKey = $this->ownerIdentityKey(
                    $identity['definition_hash'],
                    $identity['query_hash'],
                );
                if (! hash_equals($identity['definition_hash'], (string) $snapshot->definition_hash)
                    || ! hash_equals($identity['query_hash'], (string) $snapshot->query_hash)) {
                    $evaluated[] = [
                        'classification' => 'foreign',
                        'identity_key' => $snapshotIdentityKey,
                    ];
                    continue;
                }
                if (! $this->ownerSourcePolicy->accepts([
                        ...$requestBoundary,
                        'expected_definition_hash' => $identity['definition_hash'],
                        'expected_query_hash' => $identity['query_hash'],
                    ], $sourceBoundary)) {
                    $evaluated[] = [
                        'classification' => 'target_invalid',
                        'identity_key' => $expectedIdentityKey,
                    ];
                    continue;
                }
                $cohort = $this->ownerSourcePolicy->cohortHash($requestBoundary, $sourceBoundary);
                $evaluated[] = [
                    'classification' => 'exact',
                    'identity_key' => $expectedIdentityKey,
                    'component' => new ProjectPortfolioHealthSourceComponent(
                        $kind,
                        (string) $snapshot->getKey(),
                        $sourceHash,
                        $version,
                        $query->asOf->format(DateTimeInterface::ATOM),
                    ),
                    'cohort' => $cohort,
                ];
            }
            $selection = $this->ownerCandidateSelector->select($evaluated);
            if ($selection['gap_code'] !== null) {
                $gaps[] = ['code' => $selection['gap_code'], 'kind' => $kind];
                continue;
            }
            $identityParts = is_string($selection['identity_key'])
                ? explode(':', $selection['identity_key'], 2)
                : [];
            if (count($identityParts) !== 2) {
                $gaps[] = ['code' => 'owner_source_integrity_invalid', 'kind' => $kind];
                continue;
            }
            [$definitionHash, $queryHash] = $identityParts;
            $discoveredIdentityIds = [];
            foreach ($snapshots as $snapshot) {
                if (hash_equals($definitionHash, (string) $snapshot->definition_hash)
                    && hash_equals($queryHash, (string) $snapshot->query_hash)) {
                    $discoveredIdentityIds[] = (string) $snapshot->getKey();
                }
            }
            $storedIdentityIds = ProjectFinanceSnapshot::query()
                ->where('organization_id', $context->scope->organizationId)
                ->where('report_code', $kind)
                ->where('scope_hash', $scopeHash)
                ->where('query_hash', $queryHash)
                ->orderBy('id')
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->all();
            if (! $this->ownerCandidateSelector->identitySetIsComplete(
                $discoveredIdentityIds,
                $storedIdentityIds,
            )) {
                $gaps[] = ['code' => 'owner_source_integrity_invalid', 'kind' => $kind];
                continue;
            }
            if ($selection['component'] !== null) {
                $components[] = $selection['component'];
            }
            if ($selection['cohort'] !== null) {
                $cohorts[$kind][] = $selection['cohort'];
            }
        }
        if (count($cohorts['project_margin'] ?? []) === 1
            && count($cohorts['budget_plan_fact'] ?? []) === 1
            && $cohorts['project_margin'][0] !== $cohorts['budget_plan_fact'][0]) {
            $gaps[] = ['code' => 'owner_source_cohort_mismatch', 'kind' => 'project_margin'];
            $gaps[] = ['code' => 'owner_source_cohort_mismatch', 'kind' => 'budget_plan_fact'];
        }
        if ($responsibilityCenterIds === null || $counterpartyIds === null || $currencies === null) {
            $gaps[] = ['code' => 'liquidity_source_scope_invalid', 'kind' => 'portfolio_liquidity'];

            return ['components' => $components, 'gaps' => $gaps];
        }
        try {
            $source = $this->liquidity->read(
                $context->scope->organizationId,
                new PaymentCalendarSourceFilters(
                    $context->scope->organizationId,
                    $period['from'],
                    $period['to'],
                    count($projectIds) === 1 ? $projectIds[0] : null,
                    counterpartyId: count($counterpartyIds) === 1 ? $counterpartyIds[0] : null,
                    responsibilityCenterId: count($responsibilityCenterIds) === 1 ? $responsibilityCenterIds[0] : null,
                    currency: count($currencies) === 1 ? $currencies[0] : null,
                ),
                $query->asOf,
                $query->asOf,
            );
            $calendar = $this->selectedCalendar(
                $source['calendar'] ?? [],
                $context->scope->organizationId,
                $projectIds,
                $responsibilityCenterIds,
                $counterpartyIds,
                $currencies,
            );
            $versions = $this->relevantLiquidityVersions($source['versions'] ?? [], $calendar);
            $liquidityGaps = is_array($source['gaps'] ?? null) ? $source['gaps'] : [];
            $evidence = (new ProjectPortfolioHealthLiquidityEvidenceFactory)->make(
                $calendar,
                $versions,
                $liquidityGaps,
                $query->asOf->format(DateTimeInterface::ATOM),
            );
            if ($evidence instanceof ProjectPortfolioHealthSourceGap) {
                $gaps[] = $evidence->canonicalIdentity();
            } else {
                $components[] = $evidence;
            }
        } catch (ReportContractException $exception) {
            if ($exception->errorCode !== ReportErrorCode::REPORT_SOURCE_UNAVAILABLE) {
                throw $exception;
            }
            $gaps[] = ['code' => 'liquidity_source_unavailable', 'kind' => 'portfolio_liquidity'];
        } catch (\InvalidArgumentException) {
            $gaps[] = ['code' => 'liquidity_source_unavailable', 'kind' => 'portfolio_liquidity'];
        }

        return ['components' => $components, 'gaps' => $gaps];
    }

    /** @return array<string, array{formula:string,schema:string}> */
    private function ownerContracts(): array
    {
        return [
            'project_margin' => ['formula' => ProjectMarginCandidateContract::FORMULA_VERSION, 'schema' => (new ProjectMarginCandidateContract)->sourceSchemaVersion],
            'budget_plan_fact' => ['formula' => BudgetPlanFactCandidateContract::FORMULA_VERSION, 'schema' => (new BudgetPlanFactCandidateContract)->sourceSchemaVersion],
            'wip_completion_forecast' => ['formula' => WipCompletionForecastCandidateContract::FORMULA_VERSION, 'schema' => WipCompletionForecastCandidateContract::SOURCE_SCHEMA_VERSION],
        ];
    }

    /**
     * @param array<string,mixed> $request
     * @param array<string,mixed> $source
     * @return array{definition_hash:string,query_hash:string}|null
     */
    private function ownerQueryIdentity(
        ReportExecutionContext $context,
        ReportQuery $query,
        string $kind,
        array $request,
        array $source,
    ): ?array {
        $filters = $this->ownerSourcePolicy->canonicalQueryFilters($request, $source);
        if ($filters === null) {
            return null;
        }
        try {
            $published = $this->definitions->published($kind);
            if ($kind === 'project_margin') {
                (new ProjectMarginCandidateContract)->assertDefinition($published->definition);
            } elseif ($kind === 'budget_plan_fact') {
                (new BudgetPlanFactCandidateContract)->assertDefinition($published->definition);
            } else {
                (new WipCompletionForecastCandidateContract)->assertDefinition($published->definition);
            }
            $ownerQuery = new ReportQuery(
                $published->definition,
                $context->scope,
                new ReportFilterSet($filters),
                $query->comparison,
                $query->asOf,
                $query->locale,
            );
        } catch (\DomainException|InvalidArgumentException|\LogicException) {
            return null;
        }

        return [
            'definition_hash' => $published->definitionHash->value,
            'query_hash' => $ownerQuery->queryHash->value,
        ];
    }

    /** @return array{from:string,to:string} */
    private function period(ReportQuery $query): array
    {
        $from = $query->filters->values['period_from'] ?? $query->asOf->format('Y-m-d');
        $to = $query->filters->values['period_to'] ?? $query->asOf->format('Y-m-d');
        if (! is_string($from) || ! is_string($to) || ! $this->date($from) || ! $this->date($to) || $from > $to || $to > $query->asOf->format('Y-m-d')) {
            throw new InvalidArgumentException('project_portfolio_health_period_invalid');
        }

        return ['from' => $from, 'to' => $to];
    }

    private function date(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value;
    }

    private function epmScope(string $kind): string
    {
        return match ($kind) {
            'project_margin' => 'project_margin',
            'budget_plan_fact' => 'plan_fact',
            'wip_completion_forecast' => 'wip_forecast',
        };
    }

    /** @return list<array<string,mixed>> */
    private function relevantLiquidityVersions(mixed $versions, array $calendar): array
    {
        $allowed = [];
        foreach ($calendar as $item) {
            if ($item instanceof PaymentCalendarItem && $item->sourceId !== null) {
                $allowed[$item->sourceType.':'.(string) $item->sourceId] = true;
            }
        }
        $versions = is_array($versions) ? $versions : [];

        return array_values(array_filter($versions, static fn (mixed $version): bool => is_array($version)
            && isset($allowed[(string) ($version['source_type'] ?? '').':'.(string) ($version['source_id'] ?? '')])));
    }

    /** @return list<PaymentCalendarItem> */
    private function selectedCalendar(
        mixed $calendar,
        int $organizationId,
        array $projectIds,
        array $responsibilityCenterIds,
        array $counterpartyIds,
        array $currencies,
    ): array
    {
        if (! is_array($calendar)) {
            return [];
        }
        $allowedProjects = array_fill_keys($projectIds, true);
        $allowedResponsibilityCenters = array_fill_keys($responsibilityCenterIds, true);
        $allowedCounterparties = array_fill_keys($counterpartyIds, true);
        $allowedCurrencies = array_fill_keys($currencies, true);

        return array_values(array_filter($calendar, static function (mixed $item) use (
            $organizationId,
            $allowedProjects,
            $allowedResponsibilityCenters,
            $allowedCounterparties,
            $allowedCurrencies,
        ): bool {
            if (! $item instanceof PaymentCalendarItem
                || $item->organizationId !== $organizationId
                || $item->projectId === null
                || ! isset($allowedProjects[$item->projectId])
                || ($allowedResponsibilityCenters !== []
                    && ($item->responsibilityCenterId === null
                        || ! isset($allowedResponsibilityCenters[(int) $item->responsibilityCenterId])))
                || ($allowedCounterparties !== []
                    && ($item->counterpartyId === null || ! isset($allowedCounterparties[$item->counterpartyId])))) {
                return false;
            }
            $currency = mb_strtoupper(trim($item->currency));

            return $allowedCurrencies === [] || isset($allowedCurrencies[$currency]);
        }));
    }

    /** @return list<int>|null */
    private function filterIds(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return null;
        }
        $ids = [];
        foreach ($value as $item) {
            if (! is_int($item) && (! is_string($item) || preg_match('/^[1-9][0-9]*$/D', $item) !== 1)) {
                return null;
            }
            $id = (int) $item;
            if ($id < 1) {
                return null;
            }
            $ids[$id] = $id;
        }
        ksort($ids, SORT_NUMERIC);

        return array_values($ids);
    }

    /** @return list<string>|null */
    private function filterCurrencies(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return null;
        }
        $currencies = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                return null;
            }
            $currency = mb_strtoupper(trim($item));
            if (CurrencyCode::tryFrom($currency) === null) {
                return null;
            }
            $currencies[$currency] = $currency;
        }
        ksort($currencies, SORT_STRING);

        return array_values($currencies);
    }

    /** @return list<int>|null */
    private function projectIds(ReportExecutionContext $context, ReportQuery $query): ?array
    {
        if ($context->scope->canonicalIdentity() !== $query->scope->canonicalIdentity()
            || $context->scope->projectIds === []) {
            return null;
        }
        $selected = $query->filters->values['project_ids'] ?? $context->scope->projectIds;
        if (! is_array($selected) || ! array_is_list($selected)) {
            return null;
        }
        $selected = $this->filterIds($selected);

        if ($selected === null
            || $selected === []
            || array_diff($selected, $context->scope->projectIds) !== []
            || (count($selected) > 1 && $selected !== $context->scope->projectIds)) {
            return null;
        }

        return $selected;
    }

    /** @param list<int> $ids @return list<string>|null */
    private function responsibilityCenterUuids(int $organizationId, array $ids): ?array
    {
        if ($ids === []) {
            return [];
        }
        $rows = ResponsibilityCenter::query()
            ->forOrganization($organizationId)
            ->whereIn('id', $ids)
            ->get(['id', 'uuid']);
        if ($rows->count() !== count($ids)) {
            return null;
        }
        $uuids = [];
        foreach ($rows as $row) {
            $id = (int) $row->id;
            $uuid = is_string($row->uuid) ? mb_strtolower(trim($row->uuid)) : '';
            if (! in_array($id, $ids, true)
                || preg_match(
                    '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',
                    $uuid,
                ) !== 1) {
                return null;
            }
            $uuids[$uuid] = $uuid;
        }
        if (count($uuids) !== count($ids)) {
            return null;
        }
        ksort($uuids, SORT_STRING);

        return array_values($uuids);
    }

    private function ownerIdentityKey(string $definitionHash, string $queryHash): string
    {
        return $definitionHash.':'.$queryHash;
    }

    private function parentScopeMatches(EpmDataMartSnapshot $snapshot): bool
    {
        if (! is_array($snapshot->filters)
            || $snapshot->period_start === null
            || $snapshot->period_end === null
            || $snapshot->as_of_date === null
            || preg_match('/^[a-f0-9]{64}$/D', (string) $snapshot->scope_hash) !== 1) {
            return false;
        }
        try {
            $scope = EpmDataMartScope::fromInput((string) $snapshot->report_scope, [
                ...$snapshot->filters,
                'organization_id' => (int) $snapshot->organization_id,
                'period_start' => $snapshot->period_start->format('Y-m-d'),
                'period_end' => $snapshot->period_end->format('Y-m-d'),
                'as_of_date' => $snapshot->as_of_date->format('Y-m-d'),
                'project_id' => $snapshot->project_id,
                'currency' => $snapshot->currency,
            ]);
        } catch (InvalidArgumentException|\JsonException) {
            return false;
        }

        return hash_equals((string) $snapshot->scope_hash, $scope->scopeHash());
    }
}
