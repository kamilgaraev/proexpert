<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence;

use App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceIdentity;
use App\Enums\CurrencyCode;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use DomainException;

final readonly class AssistantLiveProjectFinanceProjection
{
    private const FIELDS = ['plan_revenue', 'plan_cost', 'forecast_revenue', 'forecast_cost', 'actual_revenue', 'actual_cost'];
    private const SOURCES = ['budget_amount', 'contract_performance_act', 'completed_work', 'payment_document', 'warehouse_movement', 'time_entry', 'machinery_shift', 'machinery_maintenance'];
    private const LABELS = ['plan_revenue' => 'Плановая выручка', 'plan_cost' => 'Плановые затраты', 'forecast_revenue' => 'Прогноз выручки', 'forecast_cost' => 'Прогноз затрат',
        'actual_revenue' => 'Фактическая выручка', 'actual_cost' => 'Фактические затраты', 'margin' => 'Фактическая маржа'];

    public function project(array $native, int $organizationId, int $projectId): array
    {
        if (($native['status'] ?? null) !== 'success') { return $this->insufficient((string) ($native['reason'] ?? 'source_unavailable')); }
        $filters = $native['filters'] ?? [];
        if (! is_array($filters) || (int) ($filters['organization_id'] ?? 0) !== $organizationId || ($filters['project_id'] ?? null) !== $projectId) {
            throw new DomainException('live_project_finance_scope_invalid');
        }
        $sources = $native['sources'] ?? [];
        $aggregates = $native['aggregates'] ?? [];
        $coverage = $native['coverage'] ?? [];
        if (! is_array($sources) || ! is_array($aggregates) || ! is_array($coverage) || count($sources) > 500) { return $this->insufficient('source_coverage_incomplete'); }
        $sourceSums = [];
        foreach ($sources as $source) {
            if (! is_array($source) || ! in_array($source['source_type'] ?? null, self::SOURCES, true)
                || ! is_numeric($source['source_id'] ?? null) || (int) ($source['project_id'] ?? 0) !== $projectId
                || ($source['problem_flags'] ?? '') !== '') { return $this->insufficient('source_coverage_incomplete'); }
            $currency = $this->currency($source['currency'] ?? null);
            $component = $source['component'] ?? null;
            $direction = $source['direction'] ?? null;
            if (! in_array($component, ['plan', 'actual'], true) || ! in_array($direction, ['revenue', 'cost'], true)) { return $this->insufficient('source_schema_unavailable'); }
            $field = $component.'_'.$direction;
            $sourceSums[$currency][$field] = ($sourceSums[$currency][$field] ?? BigDecimal::zero())->plus($this->decimal($source['amount_without_vat'] ?? null));
            if ($component === 'plan') {
                $field = 'forecast_'.$direction;
                $sourceSums[$currency][$field] = ($sourceSums[$currency][$field] ?? BigDecimal::zero())->plus($this->decimal($source['management_amount'] ?? null));
            }
        }
        $aggregateSums = [];
        $totals = [];
        $aggregateCount = 0;
        foreach ($aggregates as $aggregate) {
            if (! is_array($aggregate) || (int) ($aggregate['project_id'] ?? 0) !== $projectId || ($aggregate['problem_flags'] ?? '') !== '') { return $this->insufficient('source_coverage_incomplete'); }
            $currency = $this->currency($aggregate['currency'] ?? null);
            $aggregateCount += (int) ($aggregate['source_rows_count'] ?? -1);
            foreach (self::FIELDS as $field) {
                $value = $this->decimal($aggregate[$field] ?? null);
                $aggregateSums[$currency][$field] = ($aggregateSums[$currency][$field] ?? BigDecimal::zero())->plus($value);
                $totals[$currency][$field] = ($totals[$currency][$field] ?? BigDecimal::zero())->plus($value->toScale(2, RoundingMode::HALF_UP));
            }
        }
        $coverageCount = 0;
        $coveredTypes = [];
        foreach ($coverage as $item) {
            if (! is_array($item) || ! in_array($item['source_type'] ?? null, self::SOURCES, true) || isset($coveredTypes[$item['source_type']])
                || ! is_bool($item['available'] ?? null) || (($item['available'] ?? false) === false && $item['source_type'] !== 'budget_amount')
                || (int) ($item['problem_rows_count'] ?? -1) !== 0) { return $this->insufficient('source_coverage_incomplete'); }
            $coveredTypes[$item['source_type']] = true;
            $coverageCount += (int) ($item['included_source_rows'] ?? -1);
        }
        if (array_diff(self::SOURCES, array_keys($coveredTypes)) !== []) { return $this->insufficient('source_coverage_incomplete'); }
        if ($aggregateCount !== count($sources) || $coverageCount !== count($sources) || array_diff(array_keys($sourceSums), array_keys($aggregateSums)) !== []
            || array_diff(array_keys($aggregateSums), array_keys($sourceSums)) !== []) { return $this->insufficient('source_changed_during_read'); }
        foreach ($aggregateSums as $currency => $fields) {
            foreach ($fields as $field => $value) {
                if (! $value->isEqualTo($sourceSums[$currency][$field] ?? BigDecimal::zero())) { return $this->insufficient('source_changed_during_read'); }
            }
        }
        if ($totals === []) { return $this->insufficient('no_financial_sources'); }
        if (! is_string($native['fetched_at'] ?? null) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $native['fetched_at']) !== 1) { return $this->insufficient('source_time_unavailable'); }
        $fetchedAt = new DateTimeImmutable($native['fetched_at']);
        if ($fetchedAt > new DateTimeImmutable('+1 minute') || ! is_string($native['fetched_at'] ?? null)) { return $this->insufficient('source_time_unavailable'); }
        $sourceHash = AssistantSourceReferenceIdentity::key(['sources' => $sources, 'filters' => $filters, 'budget_version' => $native['budget_version'] ?? null]);
        $rows = [];
        $lines = ['Точные суммы проекта за '.$filters['period_start'].' — '.$filters['period_end'].'. Данные прочитаны '.$fetchedAt->format(DATE_ATOM).'.'];
        foreach ($totals as $currency => $fields) {
            $fields['margin'] = $fields['actual_revenue']->minus($fields['actual_cost']);
            if (($native['budget_version'] ?? null) === null) {
                foreach (['plan_revenue', 'plan_cost', 'forecast_revenue', 'forecast_cost'] as $field) { unset($fields[$field]); }
            }
            $values = array_map(static fn (BigDecimal $value): string => (string) $value->toScale(2, RoundingMode::HALF_UP), $fields);
            $version = AssistantSourceReferenceIdentity::key(['source_hash' => $sourceHash, 'currency' => $currency, 'fields' => $values]);
            $ref = ['entity_type' => 'live_project_financial_projection', 'entity_id' => $projectId, 'organization_id' => $organizationId,
                'projection_name' => 'live_project_financial_projection', 'composite_key' => ['project_id' => $projectId, 'period_start' => $filters['period_start'], 'period_end' => $filters['period_end'], 'currency' => $currency],
                'source_hash' => $sourceHash, 'source_version' => $version, 'fetched_at' => $fetchedAt->format(DATE_ATOM), 'as_of' => $fetchedAt->format(DATE_ATOM),
                'requested_currency' => $filters['currency'] ?? null,
                'evidence_time_basis' => 'live_ledger_read', 'formula_version' => 'native_project_margin_money_scale2_half_up', 'budget_version' => $native['budget_version'] ?? null];
            $rows[] = ['entity_type' => $ref['entity_type'], 'entity_id' => $projectId, 'fields' => $values, 'currency' => $currency, 'precision' => 2, 'unit' => 'currency_major',
                'scope' => 'authorized_project_period_totals', 'source_ref' => $ref, 'source_version' => $version, 'version' => $version];
            foreach ($values as $field => $value) { $lines[] = self::LABELS[$field].': '.$value.' '.$currency; }
        }
        return ['status' => ($native['budget_version'] ?? null) === null ? 'partial' : 'success', 'useful' => ($native['budget_version'] ?? null) !== null,
            'source_covered' => true, 'source_refs' => array_column($rows, 'source_ref'), 'financial_evidence' => ['rows' => $rows, 'source_refs' => array_column($rows, 'source_ref'),
            'fetched_at' => $fetchedAt->format(DATE_ATOM), 'version' => AssistantSourceReferenceIdentity::key($rows), 'validation_status' => 'partial', 'scope' => 'authorized_project_period_totals'],
            'validation_status' => 'partial', 'server_formatted_answer' => implode("\n", $lines), 'unavailable_fields' => ($native['budget_version'] ?? null) === null ? ['plan_revenue', 'plan_cost', 'forecast_revenue', 'forecast_cost'] : []];
    }

    public function insufficient(string $reason): array
    {
        return ['status' => 'insufficient_data', 'outcome' => 'insufficient_data', 'useful' => false, 'source_covered' => false, 'reason' => $reason, 'source_refs' => [],
            'financial_evidence' => [], 'validation_status' => 'partial', 'message' => 'Для точного финансового ответа не хватает полного подтверждённого источника.'];
    }

    private function decimal(mixed $value): BigDecimal
    {
        if ((! is_int($value) && ! is_string($value)) || preg_match('/^-?\d+(?:\.\d+)?$/D', (string) $value) !== 1) { throw new DomainException('live_project_finance_noncanonical_money'); }
        return BigDecimal::of($value);
    }

    private function currency(mixed $value): string
    {
        if (! is_string($value) || CurrencyCode::tryFrom($value) === null) { throw new DomainException('live_project_finance_currency_unknown'); }
        return $value;
    }
}
