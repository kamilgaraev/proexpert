<?php

declare(strict_types=1);

namespace Tests\Unit\Report;

use App\Services\Report\CustomReportBuilderService;
use Tests\TestCase;

class CustomReportSecurityValidationTest extends TestCase
{
    public function test_rejects_custom_report_formula_sql_fragments(): void
    {
        $errors = $this->builder()->validateReportConfig([
            'name' => 'Unsafe formula',
            'report_category' => 'core',
            'data_sources' => [
                'primary' => 'projects',
            ],
            'columns_config' => [
                [
                    'field' => 'projects.name',
                    'label' => 'Name',
                    'order' => 1,
                    'formula' => '{projects.name}) UNION SELECT email FROM users --',
                    'alias' => 'leaked_email',
                ],
            ],
        ]);

        self::assertNotEmpty($errors);
    }

    public function test_rejects_custom_report_aggregation_having_sql_fragments(): void
    {
        $errors = $this->builder()->validateReportConfig([
            'name' => 'Unsafe aggregation',
            'report_category' => 'core',
            'data_sources' => [
                'primary' => 'projects',
            ],
            'columns_config' => [
                [
                    'field' => 'projects.status',
                    'label' => 'Status',
                    'order' => 1,
                ],
            ],
            'aggregations_config' => [
                'group_by' => ['projects.status'],
                'aggregations' => [
                    [
                        'field' => 'projects.budget_amount',
                        'function' => 'sum',
                        'alias' => 'total) FROM users --',
                    ],
                ],
                'having' => [
                    [
                        'field' => 'SUM(projects.budget_amount)) OR 1=1 --',
                        'operator' => '=',
                        'value' => 0,
                    ],
                ],
            ],
        ]);

        self::assertNotEmpty($errors);
    }

    public function test_rejects_sensitive_unscoped_custom_report_sources(): void
    {
        $errors = $this->builder()->validateReportConfig([
            'name' => 'Global users',
            'report_category' => 'staff',
            'data_sources' => [
                'primary' => 'users',
            ],
            'columns_config' => [
                [
                    'field' => 'users.email',
                    'label' => 'Email',
                    'order' => 1,
                ],
            ],
        ]);

        self::assertNotEmpty($errors);
    }

    public function test_accepts_safe_org_scoped_custom_report_config(): void
    {
        $errors = $this->builder()->validateReportConfig([
            'name' => 'Project budget',
            'report_category' => 'core',
            'data_sources' => [
                'primary' => 'projects',
            ],
            'columns_config' => [
                [
                    'field' => 'projects.name',
                    'label' => 'Name',
                    'order' => 1,
                ],
                [
                    'field' => 'projects.budget_amount',
                    'label' => 'Budget',
                    'order' => 2,
                    'format' => 'currency',
                ],
            ],
            'aggregations_config' => [
                'group_by' => ['projects.status'],
                'aggregations' => [
                    [
                        'field' => 'projects.budget_amount',
                        'function' => 'sum',
                        'alias' => 'total_budget',
                    ],
                ],
                'having' => [
                    [
                        'field' => 'total_budget',
                        'operator' => '>=',
                        'value' => 0,
                    ],
                ],
            ],
            'sorting_config' => [
                [
                    'field' => 'projects.name',
                    'direction' => 'asc',
                ],
            ],
        ]);

        self::assertSame([], $errors);
    }

    private function builder(): CustomReportBuilderService
    {
        return app(CustomReportBuilderService::class);
    }
}
