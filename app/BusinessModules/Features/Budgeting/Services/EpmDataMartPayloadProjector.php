<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Services;

use App\BusinessModules\Features\Budgeting\DTOs\EpmDataMartScope;
use App\BusinessModules\Features\Budgeting\DTOs\EpmDataMartSnapshotPayload;
use App\BusinessModules\Features\Budgeting\DTOs\EpmDataMartStatus;
use Throwable;

use function trans_message;

final class EpmDataMartPayloadProjector
{
    public const FORMULA_VERSION = 'epm_mart_v1_2026_06';
    private const EXCLUDED_SOURCE_OF_TRUTH_KEYS = [
        'accounting',
        'accounting_entries',
        'general_ledger',
        'gl',
        'tax',
        'tax_accounting',
        'regulated_reporting',
        'payroll',
        'legal_payroll',
    ];

    public function build(EpmDataMartScope $scope, array $payload, ?string $generatedAt = null): EpmDataMartSnapshotPayload
    {
        $generatedAt ??= $this->generatedAt($payload);
        $freshness = $this->freshness($scope, $payload, $generatedAt);
        $sourceRefs = $this->sourceRefs($scope, $payload);
        $sourceHash = $this->sourceHash($scope, $payload, $sourceRefs);
        $status = $this->status($payload, $freshness);

        return new EpmDataMartSnapshotPayload(
            status: $status,
            formulaVersion: self::FORMULA_VERSION,
            sourceHash: $sourceHash,
            generatedAt: $generatedAt,
            payload: $payload,
            freshness: $freshness,
            sourceRefs: $sourceRefs,
            aggregates: $this->aggregates($scope, $payload, $sourceHash, $sourceRefs, $generatedAt),
        );
    }

    public function errorSummary(Throwable $throwable): array
    {
        return [
            'code' => 'epm_data_mart_recalculation_failed',
            'message' => trans_message('budgeting.epm_data_mart.messages.recalculation_failed'),
            'retryable' => true,
            'failed_at' => now()->toIso8601String(),
        ];
    }

    private function generatedAt(array $payload): string
    {
        $candidates = [
            $payload['meta']['generated_at'] ?? null,
            $payload['freshness']['generated_at'] ?? null,
            $payload['meta']['freshness']['generated_at'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return $candidate;
            }
        }

        return now()->toIso8601String();
    }

    private function freshness(EpmDataMartScope $scope, array $payload, string $generatedAt): array
    {
        $status = $this->payloadFreshnessStatus($payload);

        return [
            'status' => $status,
            'calculation_source' => 'data_mart',
            'report_scope' => $scope->reportScope,
            'generated_at' => $generatedAt,
            'formula_version' => self::FORMULA_VERSION,
            'row_counts' => $this->rowCounts($payload),
            'warnings_count' => count($this->warnings($payload)),
        ];
    }

    private function sourceRefs(EpmDataMartScope $scope, array $payload): array
    {
        return [
            'report_scope' => $scope->reportScope,
            'management_source_of_truth' => 'prohelper',
            'external_confirmation' => [
                '1c' => [
                    'role' => 'freshness_confirmation_only',
                    'stores_accounting_duplicate' => false,
                ],
                'bank' => [
                    'role' => 'payment_confirmation_only',
                ],
                'edo' => [
                    'role' => 'document_confirmation_only',
                ],
            ],
            'excluded' => [
                'accounting_entries',
                'general_ledger',
                'tax_accounting',
                'regulated_reporting',
                'legal_payroll',
            ],
            'source_of_truth' => $this->safeSourceOfTruth($payload),
            'row_counts' => $this->rowCounts($payload),
            'problem_flags_count' => count($this->flags($payload, 'problem_flags')),
            'risk_flags_count' => count($this->flags($payload, 'risk_flags')),
        ];
    }

    private function sourceHash(EpmDataMartScope $scope, array $payload, array $sourceRefs): string
    {
        $fingerprint = [
            'scope' => $scope->toArray(),
            'summary' => $payload['summary'] ?? null,
            'totals_by_currency' => $payload['totals_by_currency'] ?? null,
            'freshness' => $payload['freshness'] ?? $payload['meta']['freshness'] ?? null,
            'source_refs' => $sourceRefs,
            'row_counts' => $this->rowCounts($payload),
        ];

        return hash('sha256', json_encode($this->normalize($fingerprint), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function status(array $payload, array $freshness): string
    {
        $freshnessStatus = EpmDataMartStatus::normalize($freshness['status'] ?? null);

        if ($freshnessStatus === EpmDataMartStatus::STALE) {
            return EpmDataMartStatus::STALE;
        }

        if ($freshnessStatus === EpmDataMartStatus::PARTIAL || $this->hasPartialSignals($payload)) {
            return EpmDataMartStatus::PARTIAL;
        }

        return EpmDataMartStatus::SUCCEEDED;
    }

    private function payloadFreshnessStatus(array $payload): string
    {
        foreach ([
            $payload['freshness']['status'] ?? null,
            $payload['meta']['freshness']['status'] ?? null,
            $payload['summary']['quality_status'] ?? null,
            $payload['summary']['freshness_status'] ?? null,
        ] as $status) {
            if (!is_string($status)) {
                continue;
            }

            $normalized = mb_strtolower($status);
            if (in_array($normalized, [EpmDataMartStatus::STALE, 'unavailable'], true)) {
                return $normalized === 'unavailable' ? EpmDataMartStatus::PARTIAL : EpmDataMartStatus::STALE;
            }

            if (in_array($normalized, [EpmDataMartStatus::PARTIAL, 'attention'], true)) {
                return EpmDataMartStatus::PARTIAL;
            }
        }

        return EpmDataMartStatus::SUCCEEDED;
    }

    private function hasPartialSignals(array $payload): bool
    {
        if ($this->warnings($payload) !== []) {
            return true;
        }

        foreach ($this->flags($payload, 'problem_flags') as $flag) {
            $code = is_array($flag) ? (string) ($flag['code'] ?? '') : (string) $flag;
            if (str_contains($code, 'unavailable') || str_contains($code, 'partial')) {
                return true;
            }
        }

        return false;
    }

    private function aggregates(EpmDataMartScope $scope, array $payload, string $sourceHash, array $sourceRefs, string $generatedAt): array
    {
        $rows = match ($scope->reportScope) {
            EpmDataMartScope::CFO_COMMAND_CENTER => $this->cfoAggregates($payload),
            EpmDataMartScope::PROJECT_PORTFOLIO_DASHBOARD => $this->portfolioAggregates($payload),
            default => $this->rowAggregates($payload),
        };

        return array_map(function (array $row) use ($scope, $sourceHash, $sourceRefs, $generatedAt): array {
            return [
                'organization_id' => $scope->organizationId,
                'report_scope' => $scope->reportScope,
                'scope_hash' => $scope->scopeHash(),
                'aggregate_key' => (string) $row['aggregate_key'],
                'formula_version' => self::FORMULA_VERSION,
                'source_hash' => $sourceHash,
                'period_start' => $scope->periodStart,
                'period_end' => $scope->periodEnd,
                'as_of_date' => $scope->asOfDate,
                'project_id' => $row['project_id'] ?? $scope->projectId,
                'currency' => $row['currency'] ?? $scope->currency,
                'dimensions' => $row['dimensions'] ?? [],
                'metrics' => $row['metrics'] ?? [],
                'source_refs' => $sourceRefs,
                'generated_at' => $generatedAt,
            ];
        }, $rows);
    }

    private function cfoAggregates(array $payload): array
    {
        $rows = [[
            'aggregate_key' => 'summary',
            'dimensions' => ['scope' => 'cfo_command_center'],
            'metrics' => $this->metricsFromArray($payload['summary'] ?? []),
        ]];

        foreach (($payload['aggregates'] ?? []) as $key => $aggregate) {
            if (!is_array($aggregate)) {
                continue;
            }

            $rows[] = [
                'aggregate_key' => 'aggregate:' . (string) $key,
                'dimensions' => ['component' => (string) $key],
                'metrics' => $this->metricsFromArray($aggregate['summary'] ?? $aggregate),
            ];
        }

        return $rows;
    }

    private function portfolioAggregates(array $payload): array
    {
        $rows = [];

        foreach (($payload['projects'] ?? []) as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $projectId = $this->projectId($row);
            $currency = is_string($row['currency'] ?? null) ? mb_strtoupper($row['currency']) : null;

            $rows[] = [
                'aggregate_key' => sprintf('project:%s:%s:%d', $projectId ?? 'unknown', $currency ?? 'mixed', $index),
                'project_id' => $projectId,
                'currency' => $currency,
                'dimensions' => [
                    'project' => $this->compactEntity($row['project'] ?? null),
                    'risk_level' => $row['risk_level'] ?? null,
                ],
                'metrics' => $this->metricsFromArray([
                    'metrics' => $row['metrics'] ?? [],
                    'budget' => $row['budget'] ?? [],
                    'cash_gap' => $row['cash_gap'] ?? [],
                ]),
            ];
        }

        return $rows === [] ? $this->rowAggregates($payload) : $rows;
    }

    private function rowAggregates(array $payload): array
    {
        $rows = [];
        $sourceRows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];

        if ($sourceRows === [] && is_array($payload['projects'] ?? null)) {
            $sourceRows = $payload['projects'];
        }

        foreach ($sourceRows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $projectId = $this->projectId($row);
            $currency = is_string($row['currency'] ?? null) ? mb_strtoupper($row['currency']) : null;
            $dimensions = [
                'group' => $row['group'] ?? null,
                'project' => $this->compactEntity($row['project'] ?? null),
                'contract' => $this->compactEntity($row['contract'] ?? null),
                'counterparty' => $this->compactEntity($row['counterparty'] ?? null),
                'budget_article' => $this->compactEntity($row['budget_article'] ?? null),
                'responsibility_center' => $this->compactEntity($row['responsibility_center'] ?? null),
            ];

            $rows[] = [
                'aggregate_key' => 'row:' . hash('sha256', json_encode([$index, $dimensions, $currency], JSON_THROW_ON_ERROR)),
                'project_id' => $projectId,
                'currency' => $currency,
                'dimensions' => array_filter($dimensions, static fn (mixed $value): bool => $value !== null && $value !== []),
                'metrics' => $this->metricsFromArray($row),
            ];
        }

        if ($rows === []) {
            $rows[] = [
                'aggregate_key' => 'summary',
                'dimensions' => ['scope' => 'summary'],
                'metrics' => $this->metricsFromArray($payload['summary'] ?? []),
            ];
        }

        return $rows;
    }

    private function metricsFromArray(array $value): array
    {
        $metrics = [];

        foreach ($value as $key => $item) {
            if (is_int($item) || is_float($item) || is_bool($item)) {
                $metrics[(string) $key] = $item;
                continue;
            }

            if (is_array($item)) {
                $nested = $this->metricsFromArray($item);
                if ($nested !== []) {
                    $metrics[(string) $key] = $nested;
                }
            }
        }

        return $metrics;
    }

    private function safeSourceOfTruth(array $payload): array
    {
        $source = $payload['source_of_truth'] ?? $payload['meta']['source_of_truth'] ?? [];

        return is_array($source) ? $this->sanitizeSourceOfTruth($source) : [];
    }

    private function sanitizeSourceOfTruth(array $source): array
    {
        $sanitized = [];

        foreach ($source as $key => $value) {
            if (in_array(mb_strtolower((string) $key), self::EXCLUDED_SOURCE_OF_TRUTH_KEYS, true)) {
                continue;
            }

            $sanitized[(string) $key] = is_array($value) ? $this->sanitizeSourceOfTruth($value) : $value;
        }

        return array_filter($sanitized, static fn (mixed $value): bool => $value !== []);
    }

    private function rowCounts(array $payload): array
    {
        return [
            'rows' => is_array($payload['rows'] ?? null) ? count($payload['rows']) : 0,
            'projects' => is_array($payload['projects'] ?? null) ? count($payload['projects']) : 0,
            'items' => is_array($payload['items'] ?? null) ? array_sum(array_map(static fn (mixed $items): int => is_array($items) ? count($items) : 0, $payload['items'])) : 0,
            'sources_coverage' => is_array($payload['sources_coverage'] ?? null) ? count($payload['sources_coverage']) : 0,
        ];
    }

    private function warnings(array $payload): array
    {
        return array_values(array_filter(is_array($payload['warnings'] ?? null) ? $payload['warnings'] : []));
    }

    private function flags(array $payload, string $key): array
    {
        return array_values(array_filter(is_array($payload[$key] ?? null) ? $payload[$key] : []));
    }

    private function projectId(array $row): ?int
    {
        $project = $row['project'] ?? null;
        $value = is_array($project) ? ($project['id'] ?? null) : ($row['project_id'] ?? null);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function compactEntity(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        return array_filter([
            'id' => is_numeric($value['id'] ?? null) ? (int) $value['id'] : null,
            'uuid' => is_string($value['uuid'] ?? null) ? $value['uuid'] : null,
            'name' => is_string($value['name'] ?? null) ? $value['name'] : null,
            'number' => is_string($value['number'] ?? null) ? $value['number'] : null,
            'code' => is_string($value['code'] ?? null) ? $value['code'] : null,
        ], static fn (mixed $item): bool => $item !== null && $item !== '');
    }

    private function normalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[$key] = $this->normalize($item);
        }

        ksort($normalized);

        return $normalized;
    }
}
