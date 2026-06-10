<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\DTOs;

use InvalidArgumentException;

final readonly class EpmDataMartScope
{
    public const CFO_COMMAND_CENTER = 'cfo_command_center';
    public const PROJECT_PORTFOLIO_DASHBOARD = 'project_portfolio_dashboard';
    public const PROJECT_MARGIN = 'project_margin';
    public const WIP_FORECAST = 'wip_forecast';
    public const PLAN_FACT = 'plan_fact';
    public const CASH_GAP = 'cash_gap';

    public const SUPPORTED_REPORT_SCOPES = [
        self::CFO_COMMAND_CENTER,
        self::PROJECT_PORTFOLIO_DASHBOARD,
        self::PROJECT_MARGIN,
        self::WIP_FORECAST,
        self::PLAN_FACT,
        self::CASH_GAP,
    ];

    public function __construct(
        public int $organizationId,
        public string $reportScope,
        public ?string $periodStart = null,
        public ?string $periodEnd = null,
        public ?string $asOfDate = null,
        public ?int $projectId = null,
        public ?string $currency = null,
        public array $filters = [],
    ) {
        if ($this->organizationId <= 0) {
            throw new InvalidArgumentException('organization_id_required');
        }

        if (!in_array($this->reportScope, self::SUPPORTED_REPORT_SCOPES, true)) {
            throw new InvalidArgumentException('report_scope_invalid');
        }
    }

    public static function fromInput(string $reportScope, array $input): self
    {
        $organizationId = (int) ($input['organization_id'] ?? $input['current_organization_id'] ?? 0);
        $periodStart = self::nullableString($input['period_start'] ?? null);
        $periodEnd = self::nullableString($input['period_end'] ?? null);
        $asOfDate = self::nullableString($input['as_of_date'] ?? $periodEnd);
        $projectId = self::nullableInt($input['project_id'] ?? null);
        $currency = self::nullableCurrency($input['currency'] ?? null);

        return new self(
            organizationId: $organizationId,
            reportScope: self::normalizeReportScope($reportScope),
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            asOfDate: $asOfDate,
            projectId: $projectId,
            currency: $currency,
            filters: self::normalizeFilters($input),
        );
    }

    public static function normalizeReportScope(string $reportScope): string
    {
        $scope = mb_strtolower(trim($reportScope));

        if (!in_array($scope, self::SUPPORTED_REPORT_SCOPES, true)) {
            throw new InvalidArgumentException('report_scope_invalid');
        }

        return $scope;
    }

    public function scopeHash(): string
    {
        return hash('sha256', json_encode($this->canonicalScope(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function toArray(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'report_scope' => $this->reportScope,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'as_of_date' => $this->asOfDate,
            'project_id' => $this->projectId,
            'currency' => $this->currency,
            'filters' => $this->filters,
            'scope_hash' => $this->scopeHash(),
        ];
    }

    public function reportInput(): array
    {
        return array_filter([
            ...$this->filters,
            'organization_id' => $this->organizationId,
            'current_organization_id' => $this->organizationId,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'as_of_date' => $this->asOfDate,
            'project_id' => $this->projectId,
            'currency' => $this->currency,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function canonicalScope(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'report_scope' => $this->reportScope,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'as_of_date' => $this->asOfDate,
            'project_id' => $this->projectId,
            'currency' => $this->currency,
            'filters' => $this->filters,
        ];
    }

    private static function normalizeFilters(array $input): array
    {
        $ignored = [
            'current_organization_id',
            'organization_id',
            'report_scope',
            'current_project_id',
            'project_context',
            'period_start',
            'period_end',
            'as_of_date',
            'project_id',
            'currency',
            '_skip_data_mart_meta',
        ];
        $filters = [];

        foreach ($input as $key => $value) {
            if (in_array((string) $key, $ignored, true)) {
                continue;
            }

            if ($value === null || $value === '') {
                continue;
            }

            $filters[(string) $key] = self::normalizeValue($value);
        }

        ksort($filters);

        return $filters;
    }

    private static function normalizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = self::normalizeValue($item);
            }

            ksort($normalized);

            return $normalized;
        }

        if (is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : (is_numeric($value) ? (string) $value : null);
    }

    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private static function nullableCurrency(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_strtoupper(trim($value));
    }
}
