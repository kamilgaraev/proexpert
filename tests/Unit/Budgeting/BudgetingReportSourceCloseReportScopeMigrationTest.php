<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting;

use PHPUnit\Framework\TestCase;

final class BudgetingReportSourceCloseReportScopeMigrationTest extends TestCase
{
    public function test_migration_scopes_uniqueness_immutability_and_restatement_by_report(): void
    {
        $migration = file_get_contents(
            dirname(__DIR__, 3).'/app/BusinessModules/Features/Budgeting/migrations/'
            .'2026_08_05_120000_scope_budgeting_report_source_closes_by_report.php',
        );
        self::assertIsString($migration);

        self::assertStringContainsString(
            '(report_code, organization_id, period_start, period_end, scenario_identity, plan_identity)',
            $migration,
        );
        self::assertStringContainsString('OLD.report_code IS DISTINCT FROM NEW.report_code', $migration);
        self::assertSame(2, substr_count($migration, 'replacement.report_code = NEW.report_code') + substr_count($migration, 'prior_close.report_code = NEW.report_code'));
        self::assertStringContainsString('budgeting_report_source_close_report_backfill_unknown', $migration);
        self::assertStringContainsString('budgeting_report_source_close_report_rollback_conflict', $migration);
    }
}
