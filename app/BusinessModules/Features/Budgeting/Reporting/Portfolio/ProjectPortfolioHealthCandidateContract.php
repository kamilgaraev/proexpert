<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\Portfolio;

use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\DTO\ProjectPortfolioHealthRow;
use InvalidArgumentException;
use ReflectionClass;

final readonly class ProjectPortfolioHealthCandidateContract
{
    public const CODE = 'project_portfolio_health';
    public const FORMULA_VERSION = 'budgeting.project-portfolio-health.v1';
    public const SOURCE_SCHEMA_VERSION = 'project_portfolio_health_immutable_tuple_v1';
    public const FORMULA_HASH = '63702681e2de31ca6ce2fcea88b33eba9defb8c4dc2dcead097180c174449133';
    public const SOURCE_HASH = '4baed6439c1af7e4faff53fb56458909f95ab1f4f43ae57af88b134513184f43';

    public function filters(): array
    {
        return array_map(static fn (string $id): array => ['id' => $id, 'required' => false], ['as_of', 'period_from', 'period_to', 'project_ids', 'manager_ids', 'project_statuses', 'responsibility_center_ids', 'counterparty_ids', 'currencies', 'risk_levels']);
    }

    public function columns(): array
    {
        return array_map(static fn (string $id): array => ['id' => $id], ['row_key', 'project', 'currency', 'revenue', 'cost', 'margin', 'margin_percent', 'wip', 'ftc', 'eac', 'ctc', 'risk', 'drill']);
    }

    public function sorts(): array
    {
        return array_map(static fn (string $id): array => ['id' => $id, 'direction' => 'asc'], ['risk_rank', 'project_name', 'revenue', 'cost', 'margin', 'margin_percent', 'wip', 'ftc', 'eac', 'ctc']);
    }

    public function formats(): array { return ['csv', 'xlsx']; }

    public function assertRuntimeMatches(): void
    {
        if (! hash_equals(self::FORMULA_HASH, $this->classHash(ProjectPortfolioHealthRow::class))
            || ! hash_equals(self::SOURCE_HASH, $this->classHash(ProjectPortfolioHealthImmutableProjectionService::class))) {
            throw new InvalidArgumentException('project_portfolio_health_candidate_contract_drift');
        }
    }

    private function classHash(string $class): string
    {
        $file = (new ReflectionClass($class))->getFileName();
        $hash = is_string($file) ? hash_file('sha256', $file) : false;
        if (! is_string($hash)) {
            throw new InvalidArgumentException('project_portfolio_health_candidate_source_unreadable');
        }
        return $hash;
    }
}
