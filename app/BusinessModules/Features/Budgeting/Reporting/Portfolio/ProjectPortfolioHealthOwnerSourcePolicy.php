<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\Portfolio;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Budgeting\DTOs\PlanFactReportFilters;
use App\BusinessModules\Features\Budgeting\DTOs\ProjectMarginReportFilters;
use App\Enums\CurrencyCode;
use DateTimeImmutable;
use DateTimeInterface;

final class ProjectPortfolioHealthOwnerSourcePolicy
{
    /** @param array<string,mixed> $request @param array<string,mixed> $source */
    public function accepts(array $request, array $source): bool
    {
        foreach ([
            'organization_id',
            'kind',
            'epm_scope',
            'scope_hash',
            'period_from',
            'period_to',
            'as_of',
            'as_of_date',
            'formula',
            'schema',
            'parent_formula',
            'expected_definition_hash',
            'expected_query_hash',
        ] as $key) {
            if (! array_key_exists($key, $request)) {
                return false;
            }
        }
        foreach ([
            'organization_id',
            'report_code',
            'scope_hash',
            'definition_hash',
            'query_hash',
            'source_hash',
            'source_snapshot_kind',
            'source_snapshot_id',
            'source_snapshot_hash',
            'formula',
            'schema',
            'quality_status',
            'coverage_numerator',
            'coverage_denominator',
            'row_count',
            'rows_count',
            'row_organization_ids',
            'row_report_codes',
            'period_from',
            'period_to',
            'as_of',
            'generated_at',
            'stale_at',
            'parent_uuid',
            'parent_organization_id',
            'parent_report_scope',
            'parent_scope_hash_valid',
            'parent_status',
            'parent_superseded_at',
            'parent_formula',
            'parent_source_hash',
            'parent_generated_at',
            'parent_stale_at',
            'parent_period_from',
            'parent_period_to',
            'parent_as_of_date',
            'budget_version_id',
            'forecast_version_id',
            'closure_hash',
        ] as $key) {
            if (! array_key_exists($key, $source)) {
                return false;
            }
        }

        $organizationId = $this->positiveInt($request['organization_id']);
        $rowCount = $this->nonNegativeInt($source['row_count']);
        $coverageNumerator = $this->nonNegativeInt($source['coverage_numerator']);
        $coverageDenominator = $this->nonNegativeInt($source['coverage_denominator']);
        $rowsCount = $this->nonNegativeInt($source['rows_count']);
        $asOf = $this->dateTime($request['as_of']);
        $sourceAsOf = $this->dateTime($source['as_of']);
        $generatedAt = $this->dateTime($source['generated_at']);
        $staleAt = $this->dateTime($source['stale_at']);
        $parentGeneratedAt = $this->dateTime($source['parent_generated_at']);
        $parentStaleAt = $this->dateTime($source['parent_stale_at']);
        $kind = is_string($request['kind']) ? $request['kind'] : '';
        $epmScope = is_string($request['epm_scope']) ? $request['epm_scope'] : '';
        $sourceSnapshotId = is_string($source['source_snapshot_id']) ? trim($source['source_snapshot_id']) : '';
        $parentUuid = is_string($source['parent_uuid']) ? trim($source['parent_uuid']) : '';

        if ($organizationId === null
            || $kind === ''
            || $epmScope === ''
            || ! $this->validHash($request['scope_hash'])
            || ! $this->validDate($request['period_from'])
            || ! $this->validDate($request['period_to'])
            || ! $this->validDate($request['as_of_date'])
            || $asOf === null
            || $sourceAsOf === null || $sourceAsOf != $asOf
            || $generatedAt === null || $generatedAt > $asOf
            || $staleAt === null || $staleAt <= $asOf
            || $parentGeneratedAt === null || $parentGeneratedAt > $asOf
            || $parentStaleAt === null || $parentStaleAt <= $asOf
            || $rowCount === null
            || $coverageNumerator === null || $coverageNumerator !== $rowCount
            || $coverageDenominator === null || $coverageDenominator !== $rowCount
            || $rowsCount === null || $rowsCount !== $rowCount
            || $this->positiveInt($source['organization_id']) !== $organizationId
            || $this->positiveInt($source['parent_organization_id']) !== $organizationId
            || ! $this->allIdsEqual($source['row_organization_ids'], $organizationId)
            || ! $this->allStringsEqual($source['row_report_codes'], $kind)
            || $source['report_code'] !== $kind
            || $source['scope_hash'] !== $request['scope_hash']
            || $source['formula'] !== $request['formula']
            || $source['schema'] !== $request['schema']
            || $source['quality_status'] !== 'complete'
            || $source['period_from'] !== $request['period_from']
            || $source['period_to'] !== $request['period_to']
            || ! $this->validHash($source['definition_hash'])
            || ! $this->validHash($source['query_hash'])
            || ! hash_equals((string) $request['expected_definition_hash'], (string) $source['definition_hash'])
            || ! hash_equals((string) $request['expected_query_hash'], (string) $source['query_hash'])
            || ! $this->validHash($source['source_hash'])
            || ! $this->validHash($source['source_snapshot_hash'])
            || $source['source_snapshot_kind'] !== 'budgeting_epm_data_mart'
            || $sourceSnapshotId === '' || $sourceSnapshotId !== $parentUuid
            || $source['parent_report_scope'] !== $epmScope
            || $source['parent_scope_hash_valid'] !== true
            || $source['parent_status'] !== 'succeeded'
            || $source['parent_superseded_at'] !== null
            || $source['parent_formula'] !== $request['parent_formula']
            || ! $this->validHash($source['parent_source_hash'])
            || ! hash_equals((string) $source['source_snapshot_hash'], (string) $source['parent_source_hash'])
            || $source['parent_period_from'] !== $request['period_from']
            || $source['parent_period_to'] !== $request['period_to']
            || $source['parent_as_of_date'] !== $request['as_of_date']
            || $source['budget_version_id'] !== null
            || $source['forecast_version_id'] !== null
            || ! $this->closureMatches($kind, $source)) {
            return false;
        }

        return $this->matches($request, $source);
    }

    /** @param array<string,mixed> $request @param array<string,mixed> $source */
    public function matches(array $request, array $source): bool
    {
        foreach (['scope_project_ids', 'project_ids', 'currencies', 'responsibility_center_ids', 'counterparty_ids'] as $key) {
            if (! array_key_exists($key, $request)) {
                return false;
            }
        }
        foreach (['project_id', 'currency', 'filters', 'row_project_ids', 'row_currencies'] as $key) {
            if (! array_key_exists($key, $source)) {
                return false;
            }
        }
        if (! is_array($source['filters'])) {
            return false;
        }

        $scopeProjectIds = $this->ids($request['scope_project_ids']);
        $projectIds = $this->ids($request['project_ids']);
        $currencies = $this->currencies($request['currencies']);
        $responsibilityCenterIds = $this->ids($request['responsibility_center_ids']);
        $counterpartyIds = $this->ids($request['counterparty_ids']);
        $rowProjectIds = $this->ids($source['row_project_ids']);
        $rowCurrencies = $this->currencies($source['row_currencies']);
        $emptySnapshot = $this->nonNegativeInt($source['row_count'] ?? null) === 0;

        if ($scopeProjectIds === null || $scopeProjectIds === []
            || $projectIds === null || $projectIds === []
            || array_diff($projectIds, $scopeProjectIds) !== []
            || $currencies === null
            || $responsibilityCenterIds === null
            || $counterpartyIds === null
            || $rowProjectIds === null || (! $emptySnapshot && $rowProjectIds === [])
            || $rowCurrencies === null || (! $emptySnapshot && $rowCurrencies === [])) {
            return false;
        }

        if (array_key_exists('kind', $request)) {
            return ($emptySnapshot || $rowProjectIds === $projectIds)
                && ($emptySnapshot || $currencies === [] || array_diff($currencies, $rowCurrencies) === [])
                && $this->canonicalQueryFilters($request, $source) !== null;
        }

        $sourceProjectIds = $this->sourceIds($source['project_id'], $source['filters'], 'project_id', 'project_ids');
        $sourceCurrencies = $this->sourceCurrencies($source['currency'], $source['filters']);
        $sourceResponsibilityCenterIds = $this->sourceIds(
            null,
            $source['filters'],
            'responsibility_center_id',
            'responsibility_center_ids',
        );
        $sourceCounterpartyIds = $this->sourceIds(
            null,
            $source['filters'],
            'counterparty_id',
            'counterparty_ids',
        );
        if ($sourceProjectIds === null
            || $sourceCurrencies === null
            || $sourceResponsibilityCenterIds === null
            || $sourceCounterpartyIds === null) {
            return false;
        }

        $expectedSourceProjectIds = $projectIds === $scopeProjectIds ? [] : $projectIds;
        if ($sourceProjectIds !== [] && $sourceProjectIds !== $projectIds) {
            return false;
        }
        if ($sourceProjectIds === [] && $expectedSourceProjectIds !== []) {
            return false;
        }

        return $rowProjectIds === $projectIds
            && $sourceCurrencies === $currencies
            && ($currencies === [] || $rowCurrencies === $currencies)
            && $sourceResponsibilityCenterIds === $responsibilityCenterIds
            && $sourceCounterpartyIds === $counterpartyIds;
    }

    /** @param array<string,mixed> $request @param array<string,mixed> $source @return array<string,mixed>|null */
    public function canonicalQueryFilters(array $request, array $source): ?array
    {
        foreach ([
            'organization_id',
            'kind',
            'scope_project_ids',
            'project_ids',
            'currencies',
            'responsibility_center_ids',
            'responsibility_center_uuids',
            'counterparty_ids',
            'period_from',
            'period_to',
        ] as $key) {
            if (! array_key_exists($key, $request)) {
                return null;
            }
        }
        foreach (['project_id', 'currency', 'filters'] as $key) {
            if (! array_key_exists($key, $source)) {
                return null;
            }
        }
        if (! is_array($source['filters'])) {
            return null;
        }

        $organizationId = $this->positiveInt($request['organization_id']);
        $kind = is_string($request['kind']) ? $request['kind'] : '';
        $scopeProjectIds = $this->ids($request['scope_project_ids']);
        $projectIds = $this->ids($request['project_ids']);
        $currencies = $this->currencies($request['currencies']);
        $responsibilityCenterIds = $this->ids($request['responsibility_center_ids']);
        $responsibilityCenterUuids = $this->uuids($request['responsibility_center_uuids']);
        $counterpartyIds = $this->ids($request['counterparty_ids']);
        if ($organizationId === null
            || $scopeProjectIds === null || $scopeProjectIds === []
            || $projectIds === null || $projectIds === []
            || array_diff($projectIds, $scopeProjectIds) !== []
            || (count($projectIds) > 1 && $projectIds !== $scopeProjectIds)
            || $currencies === null
            || $responsibilityCenterIds === null
            || $responsibilityCenterUuids === null
            || count($responsibilityCenterIds) !== count($responsibilityCenterUuids)
            || $counterpartyIds === null
            || ! $this->validDate($request['period_from'])
            || ! $this->validDate($request['period_to'])) {
            return null;
        }

        $expectedProjectId = count($scopeProjectIds) === 1 ? $scopeProjectIds[0] : null;
        $queryProjectId = count($projectIds) === 1 ? $projectIds[0] : null;
        $sourceProjectId = $source['project_id'] === null ? null : $this->positiveInt($source['project_id']);
        $sourceCurrency = $this->nullableCurrency($source['currency']);
        if (($source['project_id'] !== null && $sourceProjectId === null)
            || ($source['currency'] !== null && $sourceCurrency === null)
            || $sourceProjectId !== $expectedProjectId
            || ($sourceCurrency !== null && $currencies !== [$sourceCurrency])) {
            return null;
        }

        $filters = $source['filters'];
        if ($kind === 'wip_completion_forecast') {
            if ($filters !== [] || $sourceCurrency !== null) {
                return null;
            }

            return [
                'period_start' => $request['period_from'],
                'period_end' => $request['period_to'],
            ];
        }
        if (! in_array($kind, ['project_margin', 'budget_plan_fact'], true)) {
            return null;
        }

        $required = ['budget_version_uuid', 'close_id', 'group_by', 'scenario_uuid'];
        $allowed = [...$required, 'counterparty_id', 'responsibility_center_id'];
        $keys = array_keys($filters);
        sort($keys, SORT_STRING);
        sort($allowed, SORT_STRING);
        if (array_diff($required, $keys) !== [] || array_diff($keys, $allowed) !== []) {
            return null;
        }
        if (! $this->nonEmptyString($filters['close_id'] ?? null)
            || ! $this->nonEmptyString($filters['scenario_uuid'] ?? null)
            || ! $this->nonEmptyString($filters['budget_version_uuid'] ?? null)) {
            return null;
        }
        $expectedGroupBy = $kind === 'project_margin'
            ? [ProjectMarginReportFilters::GROUP_PROJECT, ProjectMarginReportFilters::GROUP_CURRENCY]
            : PlanFactReportFilters::DEFAULT_GROUP_BY;
        if (($filters['group_by'] ?? null) !== $expectedGroupBy
            || ! $this->optionalStringFilterCoversSelection(
                $filters,
                'responsibility_center_id',
                $responsibilityCenterUuids,
            )
            || ! $this->optionalFilterCoversSelection($filters, 'counterparty_id', $counterpartyIds)) {
            return null;
        }

        return array_filter([
            'close_id' => $filters['close_id'],
            'organization_id' => $organizationId,
            'period_start' => $request['period_from'],
            'period_end' => $request['period_to'],
            'scenario_uuid' => $filters['scenario_uuid'],
            'budget_version_uuid' => $filters['budget_version_uuid'],
            'project_id' => $queryProjectId,
            'responsibility_center_id' => $filters['responsibility_center_id'] ?? null,
            'counterparty_id' => $filters['counterparty_id'] ?? null,
            'currency' => $sourceCurrency,
            'group_by' => $expectedGroupBy,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @param array<string,mixed> $request @param array<string,mixed> $source */
    public function cohortHash(array $request, array $source): ?string
    {
        $filters = $this->canonicalQueryFilters($request, $source);
        if ($filters === null || ! in_array($request['kind'] ?? null, ['project_margin', 'budget_plan_fact'], true)) {
            return null;
        }

        return hash('sha256', CanonicalJson::encode([
            'scenario_uuid' => $filters['scenario_uuid'],
            'budget_version_uuid' => $filters['budget_version_uuid'],
        ]));
    }

    /** @param array<string,mixed> $filters @return list<int>|null */
    private function sourceIds(mixed $topLevel, array $filters, string $singular, string $plural): ?array
    {
        $sets = [];
        if ($topLevel !== null && $topLevel !== '') {
            $sets[] = $this->ids($topLevel);
        }
        foreach ([$singular, $plural] as $key) {
            if (array_key_exists($key, $filters)) {
                $sets[] = $this->ids($filters[$key]);
            }
        }

        return $this->oneExactSet($sets);
    }

    /** @param array<string,mixed> $filters @return list<string>|null */
    private function sourceCurrencies(mixed $topLevel, array $filters): ?array
    {
        $sets = [];
        if ($topLevel !== null && $topLevel !== '') {
            $sets[] = $this->currencies($topLevel);
        }
        foreach (['currency', 'currencies'] as $key) {
            if (array_key_exists($key, $filters)) {
                $sets[] = $this->currencies($filters[$key]);
            }
        }

        return $this->oneExactSet($sets);
    }

    /**
     * @template TValue of int|string
     * @param list<list<TValue>|null> $sets
     * @return list<TValue>|null
     */
    private function oneExactSet(array $sets): ?array
    {
        $resolved = [];
        foreach ($sets as $set) {
            if ($set === null) {
                return null;
            }
            if ($set === []) {
                continue;
            }
            if ($resolved !== [] && $resolved !== $set) {
                return null;
            }
            $resolved = $set;
        }

        return $resolved;
    }

    /** @return list<int>|null */
    private function ids(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return [];
        }
        $values = is_array($value) ? $value : [$value];
        if (! array_is_list($values)) {
            return null;
        }
        $ids = [];
        foreach ($values as $item) {
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
    private function currencies(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return [];
        }
        $values = is_array($value) ? $value : [$value];
        if (! array_is_list($values)) {
            return null;
        }
        $currencies = [];
        foreach ($values as $item) {
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

    /** @return list<string>|null */
    private function uuids(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return [];
        }
        $values = is_array($value) ? $value : [$value];
        if (! array_is_list($values)) {
            return null;
        }
        $uuids = [];
        foreach ($values as $item) {
            if (! is_string($item)) {
                return null;
            }
            $uuid = mb_strtolower(trim($item));
            if (preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',
                $uuid,
            ) !== 1) {
                return null;
            }
            $uuids[$uuid] = $uuid;
        }
        ksort($uuids, SORT_STRING);

        return array_values($uuids);
    }

    private function positiveInt(mixed $value): ?int
    {
        $value = $this->nonNegativeInt($value);

        return $value !== null && $value > 0 ? $value : null;
    }

    private function nonNegativeInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (! is_string($value) || preg_match('/^(0|[1-9][0-9]*)$/D', $value) !== 1) {
            return null;
        }

        return (int) $value;
    }

    private function validHash(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    /** @param array<string,mixed> $source */
    private function closureMatches(string $kind, array $source): bool
    {
        if ($kind === 'budget_plan_fact') {
            return $this->validHash($source['closure_hash'])
                && hash_equals((string) $source['source_snapshot_hash'], (string) $source['closure_hash']);
        }

        return $source['closure_hash'] === null;
    }

    /** @param array<string,mixed> $filters @param list<int> $selected */
    private function optionalFilterCoversSelection(array $filters, string $key, array $selected): bool
    {
        if (! array_key_exists($key, $filters)) {
            return true;
        }

        return count($selected) === 1 && $this->positiveInt($filters[$key]) === $selected[0];
    }

    /** @param array<string,mixed> $filters @param list<string> $selected */
    private function optionalStringFilterCoversSelection(array $filters, string $key, array $selected): bool
    {
        if (! array_key_exists($key, $filters)) {
            return true;
        }

        return count($selected) === 1
            && is_string($filters[$key])
            && mb_strtolower(trim($filters[$key])) === $selected[0];
    }

    private function nullableCurrency(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value)) {
            return null;
        }
        $currency = mb_strtoupper(trim($value));

        return CurrencyCode::tryFrom($currency) === null ? null : $currency;
    }

    private function nonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function validDate(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value;
    }

    private function dateTime(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value)) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $value);

        return $date instanceof DateTimeImmutable && $date->format(DateTimeInterface::ATOM) === $value ? $date : null;
    }

    private function allIdsEqual(mixed $values, int $expected): bool
    {
        if (! is_array($values) || ! array_is_list($values) || $values === []) {
            return false;
        }
        foreach ($values as $value) {
            if ($this->positiveInt($value) !== $expected) {
                return false;
            }
        }

        return true;
    }

    private function allStringsEqual(mixed $values, string $expected): bool
    {
        if (! is_array($values) || ! array_is_list($values) || $values === []) {
            return false;
        }
        foreach ($values as $value) {
            if (! is_string($value) || $value !== $expected) {
                return false;
            }
        }

        return true;
    }
}
