<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Services;

use App\BusinessModules\Features\Budgeting\DTOs\BudgetImportPreviewResult;
use App\BusinessModules\Features\Budgeting\DTOs\BudgetImportValidationContext;
use Carbon\CarbonImmutable;

use function trans_message;

final class BudgetImportValidator
{
    /**
     * @param array<int, array<string, mixed>> $rawRows
     */
    public function validate(array $rawRows, BudgetImportValidationContext $context): BudgetImportPreviewResult
    {
        $rows = [];
        $duplicates = [];

        foreach ($rawRows as $rawRow) {
            $rowNumber = (int) ($rawRow['row_number'] ?? 0);
            $normalized = $this->normalizeRow($rawRow);
            $errors = [];
            $warnings = [];

            if ($context->versionStatus !== BudgetWorkflowService::STATUS_DRAFT) {
                $errors[] = trans_message('budgeting.import_errors.version_not_editable');
            }

            if (in_array($context->periodStatus, ['closed', 'archived'], true)) {
                $errors[] = trans_message('budgeting.import_errors.period_closed');
            }

            $article = $this->resolveArticle($normalized['article_code'], $context);
            if ($article === null) {
                $errors[] = trans_message('budgeting.import_errors.article_not_found');
            } else {
                if (!$article['is_active']) {
                    $errors[] = trans_message('budgeting.import_errors.article_inactive');
                }

                if (!$article['is_leaf']) {
                    $errors[] = trans_message('budgeting.import_errors.article_not_leaf');
                }

                if (!$this->articleKindCompatible($article['budget_kind'], $context->budgetKind)) {
                    $errors[] = trans_message('budgeting.import_errors.article_kind_mismatch');
                }
            }

            $center = $this->resolveCenter($normalized['cfo_code'], $context);
            if ($center === null) {
                $errors[] = trans_message('budgeting.import_errors.cfo_not_found');
            } else {
                if (!$center['is_active']) {
                    $errors[] = trans_message('budgeting.import_errors.cfo_inactive');
                }

                if (!$this->centerActiveInPeriod($center, $context)) {
                    $errors[] = trans_message('budgeting.import_errors.cfo_out_of_period');
                }
            }

            if ($normalized['month'] === null || !$this->monthInPeriod($normalized['month'], $context)) {
                $errors[] = trans_message('budgeting.import_errors.month_out_of_period');
            }

            if ($normalized['plan_amount'] === null) {
                $errors[] = trans_message('budgeting.import_errors.amount_invalid');
            }

            if ($normalized['forecast_amount'] === null) {
                $warnings[] = trans_message('budgeting.import_warnings.forecast_empty');
                $normalized['forecast_amount'] = $normalized['plan_amount'] ?? 0.0;
            }

            if ($normalized['currency'] !== $context->currency) {
                $errors[] = trans_message('budgeting.import_errors.currency_unsupported');
            }

            if ($this->dimensionUnavailable($rawRow, $normalized, 'project_id', $context->projectIds)) {
                $errors[] = trans_message('budgeting.lines.project_not_found');
            }

            if ($this->dimensionUnavailable($rawRow, $normalized, 'contract_id', $context->contractIds)) {
                $errors[] = trans_message('budgeting.lines.contract_not_found');
            }

            if ($this->dimensionUnavailable($rawRow, $normalized, 'counterparty_id', $context->counterpartyIds)) {
                $errors[] = trans_message('budgeting.lines.counterparty_not_found');
            }

            if (
                $normalized['scenario_code'] !== null
                && mb_strtolower($normalized['scenario_code']) !== mb_strtolower($context->scenarioCode)
            ) {
                $errors[] = trans_message('budgeting.import_errors.scenario_mismatch');
            }

            if (
                $normalized['budget_kind'] !== null
                && mb_strtolower($normalized['budget_kind']) !== mb_strtolower($context->budgetKind)
            ) {
                $errors[] = trans_message('budgeting.import_errors.budget_kind_mismatch');
            }

            if ($normalized['version_uuid'] !== null && $normalized['version_uuid'] !== $context->versionUuid) {
                $errors[] = trans_message('budgeting.import_errors.version_mismatch');
            }

            $duplicateKey = $this->duplicateKey($normalized);
            if (isset($duplicates[$duplicateKey])) {
                $errors[] = trans_message('budgeting.import_errors.duplicate_row');
                $rows[$duplicates[$duplicateKey]]['validation_errors'][] = trans_message('budgeting.import_errors.duplicate_row');
                $rows[$duplicates[$duplicateKey]]['validation_status'] = 'invalid';
            } else {
                $duplicates[$duplicateKey] = count($rows);
            }

            if ($article !== null) {
                $normalized['budget_article_id'] = $article['id'];
                $normalized['budget_article_uuid'] = $article['uuid'];
                $normalized['budget_article_name'] = $article['name'];
            }

            if ($center !== null) {
                $normalized['responsibility_center_id'] = $center['id'];
                $normalized['responsibility_center_uuid'] = $center['uuid'];
                $normalized['responsibility_center_name'] = $center['name'];
            }

            if ($normalized['month'] !== null) {
                $normalized['month'] = $normalized['month'] . '-01';
            }

            $normalized['plan'] = $normalized['plan_amount'] ?? 0.0;
            $normalized['forecast'] = $normalized['forecast_amount'] ?? $normalized['plan'];

            $rows[] = [
                'row_number' => $rowNumber,
                'raw_payload' => $rawRow,
                'normalized_payload' => $normalized,
                'validation_status' => $errors === [] ? ($warnings === [] ? 'valid' : 'warning') : 'invalid',
                'validation_errors' => $errors,
                'validation_warnings' => $warnings,
            ];
        }

        return new BudgetImportPreviewResult(
            rows: $rows,
            summary: $this->summary($rows, $context->currency),
        );
    }

    /**
     * @param array<string, mixed> $rawRow
     * @return array<string, mixed>
     */
    private function normalizeRow(array $rawRow): array
    {
        return [
            'article_code' => $this->stringOrNull($rawRow['article_code'] ?? null),
            'cfo_code' => $this->stringOrNull($rawRow['cfo_code'] ?? null),
            'month' => $this->normalizeMonth($rawRow['month'] ?? null),
            'plan_amount' => $this->amountOrNull($rawRow['plan_amount'] ?? null),
            'forecast_amount' => $this->amountOrNull($rawRow['forecast_amount'] ?? null),
            'currency' => strtoupper($this->stringOrNull($rawRow['currency'] ?? null) ?? 'RUB'),
            'project_id' => $this->intOrNull($rawRow['project_id'] ?? null),
            'contract_id' => $this->intOrNull($rawRow['contract_id'] ?? null),
            'counterparty_id' => $this->intOrNull($rawRow['counterparty_id'] ?? null),
            'description' => $this->stringOrNull($rawRow['description'] ?? null),
            'scenario_code' => $this->stringOrNull($rawRow['scenario_code'] ?? null),
            'budget_kind' => $this->stringOrNull($rawRow['budget_kind'] ?? null),
            'version_uuid' => $this->stringOrNull($rawRow['version_uuid'] ?? null),
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_INT) === false ? null : (int) $value;
    }

    /**
     * @param array<string, mixed> $rawRow
     * @param array<string, mixed> $normalized
     * @param array<int, true> $availableIds
     */
    private function dimensionUnavailable(array $rawRow, array $normalized, string $key, array $availableIds): bool
    {
        $rawValue = $rawRow[$key] ?? null;
        if ($rawValue === null || trim((string) $rawValue) === '') {
            return false;
        }

        if (filter_var($rawValue, FILTER_VALIDATE_INT) === false) {
            return true;
        }

        $normalizedValue = $normalized[$key] ?? null;

        return !is_int($normalizedValue) || !isset($availableIds[$normalizedValue]);
    }

    private function amountOrNull(mixed $value): ?float
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $normalized = str_replace(["\u{00A0}", ' ', ','], ['', '', '.'], (string) $value);

        return is_numeric($normalized) ? round((float) $normalized, 2) : null;
    }

    private function normalizeMonth(mixed $value): ?string
    {
        $string = $this->stringOrNull($value);
        if ($string === null) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}$/', $string) === 1) {
            return $string;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $string) === 1) {
            return substr($string, 0, 7);
        }

        if (preg_match('/^(\d{2})\.(\d{4})$/', $string, $matches) === 1) {
            return "{$matches[2]}-{$matches[1]}";
        }

        return null;
    }

    /**
     * @return array{id:int, uuid:string, name:string, budget_kind:string, is_leaf:bool, is_active:bool}|null
     */
    private function resolveArticle(?string $code, BudgetImportValidationContext $context): ?array
    {
        if ($code === null) {
            return null;
        }

        $key = mb_strtolower($code);

        return $context->mappingMode === 'by_name'
            ? ($context->articlesByName[$key] ?? null)
            : ($context->articlesByCode[$key] ?? null);
    }

    /**
     * @return array{id:int, uuid:string, name:string, is_active:bool, active_from:?string, active_to:?string}|null
     */
    private function resolveCenter(?string $code, BudgetImportValidationContext $context): ?array
    {
        if ($code === null) {
            return null;
        }

        $key = mb_strtolower($code);

        return $context->mappingMode === 'by_name'
            ? ($context->centersByName[$key] ?? null)
            : ($context->centersByCode[$key] ?? null);
    }

    private function articleKindCompatible(string $articleKind, string $budgetKind): bool
    {
        return $articleKind === $budgetKind || $articleKind === 'both';
    }

    /**
     * @param array{id:int, uuid:string, name:string, is_active:bool, active_from:?string, active_to:?string} $center
     */
    private function centerActiveInPeriod(array $center, BudgetImportValidationContext $context): bool
    {
        $activeFrom = $center['active_from'] !== null ? CarbonImmutable::parse($center['active_from']) : null;
        $activeTo = $center['active_to'] !== null ? CarbonImmutable::parse($center['active_to']) : null;

        if ($activeFrom !== null && $activeFrom->greaterThan($context->periodEnd)) {
            return false;
        }

        return !($activeTo !== null && $activeTo->lessThan($context->periodStart));
    }

    private function monthInPeriod(?string $month, BudgetImportValidationContext $context): bool
    {
        if ($month === null) {
            return false;
        }

        $date = CarbonImmutable::parse($month . '-01')->startOfMonth();

        return $date->betweenIncluded($context->periodStart->startOfMonth(), $context->periodEnd->startOfMonth());
    }

    /**
     * @param array<string, mixed> $normalized
     */
    private function duplicateKey(array $normalized): string
    {
        return implode('|', [
            mb_strtolower((string) ($normalized['article_code'] ?? '')),
            mb_strtolower((string) ($normalized['cfo_code'] ?? '')),
            (string) ($normalized['project_id'] ?? ''),
            (string) ($normalized['contract_id'] ?? ''),
            (string) ($normalized['counterparty_id'] ?? ''),
            (string) ($normalized['month'] ?? ''),
            (string) ($normalized['currency'] ?? ''),
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function summary(array $rows, string $currency): array
    {
        $valid = 0;
        $warning = 0;
        $invalid = 0;
        $planTotal = 0.0;

        foreach ($rows as $row) {
            if ($row['validation_status'] === 'valid') {
                $valid++;
                $planTotal += (float) ($row['normalized_payload']['plan_amount'] ?? 0);
            } elseif ($row['validation_status'] === 'warning') {
                $warning++;
                $planTotal += (float) ($row['normalized_payload']['plan_amount'] ?? 0);
            } else {
                $invalid++;
            }
        }

        return [
            'rows_total' => count($rows),
            'rows_valid' => $valid,
            'rows_with_warnings' => $warning,
            'rows_invalid' => $invalid,
            'plan_total' => round($planTotal, 2),
            'currency' => $currency,
        ];
    }
}
