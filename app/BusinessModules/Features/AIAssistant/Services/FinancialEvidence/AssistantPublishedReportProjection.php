<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus;
use App\BusinessModules\Features\AIAssistant\Services\AssistantExtendedDomainRegistry;
use App\Enums\CurrencyCode;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use DomainException;

final readonly class AssistantPublishedReportProjection
{
    private const MINOR_FIELDS = [
        'project_margin' => ['plan_revenue', 'actual_revenue', 'forecast_revenue', 'plan_cost', 'actual_cost', 'forecast_cost', 'margin'],
        'budget_plan_fact' => ['plan', 'actual', 'committed', 'available', 'variance'],
        'wip_completion_forecast' => ['bac', 'pv', 'ev', 'ac', 'wip', 'ctc', 'eac', 'forecast_variance'],
        'management_pnl' => ['revenue', 'direct_cost', 'gross_margin', 'operating_expense', 'operating_result'],
        'project_evm_control' => ['bac_minor', 'pv_minor', 'ev_minor', 'ac_minor', 'sv_minor', 'cv_minor', 'approved_etc_minor', 'eac_minor', 'vac_minor'],
        'holding_performance' => ['contracted_minor', 'accepted_accrual_minor', 'cash_minor'],
        'intercompany_contract_flows' => ['internal_minor', 'external_minor', 'unclassified_minor', 'total_minor'],
    ];
    private const DECIMAL_FIELDS = [
        'portfolio_liquidity' => ['opening', 'inflow', 'outflow', 'closing', 'gap'],
        'project_portfolio_health' => ['revenue', 'cost', 'margin', 'wip', 'ftc', 'eac', 'ctc'],
    ];

    public function supports(string $code): bool
    {
        return isset(self::MINOR_FIELDS[$code]) || isset(self::DECIMAL_FIELDS[$code]);
    }

    public function project(string $runId, string $code, int $organizationId, ReportResult $result, ReportPage $page, DateTimeImmutable $asOf, DateTimeImmutable $fetchedAt, ?string $cursor = null): array
    {
        $snapshot = $result->metadata->snapshot;
        if (! $this->supports($code) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $runId) !== 1 || $snapshot->scope->organizationId !== $organizationId) {
            throw new DomainException('assistant_report_projection_scope_invalid');
        }
        if ($result->freshness !== ReportFreshnessStatus::FRESH || $page->freshness !== ReportFreshnessStatus::FRESH
            || $result->quality->status !== ReportQualityStatus::COMPLETE || $page->quality->status !== ReportQualityStatus::COMPLETE
            || $result->quality->reconciliation === ReportReconciliationStatus::MISMATCH || $page->quality->reconciliation === ReportReconciliationStatus::MISMATCH
            || $result->quality->unmatchedCount > 0 || $page->quality->unmatchedCount > 0
            || $result->quality->excludedSources !== [] || $page->quality->excludedSources !== []
            || $page->limit < 1 || $page->limit > 20 || count($page->rows) > $page->limit
            || $snapshot->generatedAt > $fetchedAt || $asOf > $fetchedAt || ($snapshot->staleAt !== null && $snapshot->staleAt <= $fetchedAt)) {
            throw new DomainException('assistant_report_projection_not_current_complete');
        }
        $scale = isset(self::DECIMAL_FIELDS[$code]) ? 2 : 0;
        $unit = $scale === 2 ? 'currency_major' : 'currency_minor';
        $fields = self::DECIMAL_FIELDS[$code] ?? self::MINOR_FIELDS[$code];
        $schema = array_column($result->rowSchema, null, 'id');
        $fields = array_values(array_filter($fields, static fn (string $field): bool => isset($schema[$field]) || isset($schema[preg_replace('/_minor$/D', '', $field)])));
        if (in_array($code, ['project_margin', 'budget_plan_fact', 'wip_completion_forecast', 'management_pnl'], true)) {
            $fields = array_values(array_filter($fields, static fn (string $field): bool => ($schema[$field]['type'] ?? null) === 'money_minor'));
        }
        $rows = [];
        foreach ($page->rows as $row) {
            if (! is_array($row) || ! is_string($row['row_key'] ?? null) || $row['row_key'] === '') {
                throw new DomainException('assistant_report_projection_row_identity_missing');
            }
            $projected = $this->values($row, $fields, $scale);
            if ($projected !== []) {
                $rows[] = $this->evidenceRow($runId, $code, $organizationId, $result, $row['row_key'], $this->currency($row['currency'] ?? null), $projected, $unit, $scale, 'returned_report_row', $asOf, $fetchedAt, $cursor, $page->limit);
            }
        }
        $totals = $code === 'holding_performance' ? ($result->totals['currencies'] ?? []) : $result->totals;
        $totalFields = match ($code) {
            'project_margin', 'budget_plan_fact', 'wip_completion_forecast', 'management_pnl' => array_map(static fn (string $field): string => $field.'_minor', $fields),
            'portfolio_liquidity' => array_values(array_intersect(['inflow', 'outflow', 'closing'], $fields)),
            'project_evm_control' => [],
            default => $fields,
        };
        foreach ($totals as $currency => $group) {
            if (! is_array($group)) {
                continue;
            }
            $projected = $this->values($group, $totalFields, $scale);
            if ($projected !== []) {
                if (isset($group['currency']) && $group['currency'] !== $currency) { throw new DomainException('assistant_report_projection_currency_mismatch'); }
                $rows[] = $this->evidenceRow($runId, $code, $organizationId, $result, 'totals:'.$currency, $this->currency($currency), $projected, $unit, $scale, 'complete_report_totals', $asOf, $fetchedAt, $cursor, $page->limit);
            }
        }
        if ($rows === []) {
            throw new DomainException('assistant_report_projection_numeric_fields_unavailable');
        }
        $lines = ['Данные отчёта на '.$asOf->format('Y-m-d H:i:s P').'.'];
        $labels = AssistantExtendedDomainRegistry::values('fieldLabels');
        foreach ($rows as $row) {
            $lines[] = $row['scope'] === 'complete_report_totals' ? 'Итоги: '.$row['currency'].'.' : 'Строка '.$row['row_key'].': '.$row['currency'].'.';
            foreach ($row['fields'] as $field => $value) {
                $lines[] = ($labels[$field] ?? $field).': '.$value.($row['unit'] === 'currency_minor' ? ' минимальных денежных единиц '.$row['currency'] : ' '.$row['currency']);
            }
        }
        return ['status' => 'success', 'financial_evidence' => ['rows' => $rows, 'source_refs' => array_column($rows, 'source_ref'), 'fetched_at' => $fetchedAt->format(DATE_ATOM), 'version' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)), 'validation_status' => 'partial', 'scope' => 'published_report_snapshot', 'as_of' => $asOf->format(DATE_ATOM)],
            'source_refs' => array_column($rows, 'source_ref'), 'server_formatted_answer' => implode("\n", $lines), 'validation_status' => 'partial', 'has_more' => $page->hasMore, 'next_cursor' => $page->nextCursor];
    }

    private function values(array $source, array $fields, int $scale): array
    {
        $values = [];
        foreach ($fields as $field) {
            if (! array_key_exists($field, $source) || $source[$field] === null) {
                continue;
            }
            $value = $source[$field];
            if ((! is_int($value) && ! is_string($value)) || preg_match($scale === 0 ? '/^-?\d+$/D' : '/^-?\d+(?:\.\d{1,2})?$/D', (string) $value) !== 1) {
                throw new DomainException('assistant_report_projection_noncanonical_number');
            }
            $values[$field] = (string) BigDecimal::of($value)->toScale($scale);
        }
        return $values;
    }

    private function currency(mixed $value): string
    {
        if (! is_string($value) || CurrencyCode::tryFrom($value) === null) {
            throw new DomainException('assistant_report_projection_currency_unknown');
        }
        return $value;
    }

    private function evidenceRow(string $runId, string $code, int $organizationId, ReportResult $result, string $key, string $currency, array $fields, string $unit, int $scale, string $scope, DateTimeImmutable $asOf, DateTimeImmutable $fetchedAt, ?string $cursor, int $limit): array
    {
        $snapshot = $result->metadata->snapshot;
        $version = hash('sha256', json_encode([$code, $snapshot->sourceHash->value, $key, $currency, $unit, $fields], JSON_THROW_ON_ERROR));
        $ref = ['entity_type' => 'published_report_financial_projection', 'entity_id' => $runId, 'organization_id' => $organizationId,
            'projection_name' => 'published_report_financial_projection', 'composite_key' => ['scope' => $scope, 'row_key' => $key, 'currency' => $currency],
            'report_code' => $code, 'snapshot_kind' => $snapshot->kind, 'snapshot_id' => $snapshot->id,
            'definition_hash' => $snapshot->definitionHash->value, 'source_hash' => $snapshot->sourceHash->value, 'source_version' => $version,
            'formula_version' => $snapshot->formulaVersion, 'as_of' => $asOf->format(DATE_ATOM), 'generated_at' => $snapshot->generatedAt->format(DATE_ATOM),
            'fetched_at' => $fetchedAt->format(DATE_ATOM), 'cursor' => $cursor, 'limit' => $limit, 'evidence_time_basis' => 'snapshot_as_of'];
        return ['entity_type' => $ref['entity_type'], 'entity_id' => $runId, 'row_key' => $key, 'fields' => $fields, 'currency' => $currency, 'precision' => $scale, 'unit' => $unit, 'scope' => $scope, 'source_ref' => $ref, 'source_version' => $version, 'version' => $version];
    }
}
