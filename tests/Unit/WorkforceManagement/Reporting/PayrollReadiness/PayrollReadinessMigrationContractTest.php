<?php

declare(strict_types=1);

namespace Tests\Unit\WorkforceManagement\Reporting\PayrollReadiness;

use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\DTO\PayrollReadinessPolicyDefinition;
use PHPUnit\Framework\TestCase;

final class PayrollReadinessMigrationContractTest extends TestCase
{
    public function test_database_contract_seals_complete_item_set_once_and_rejects_late_append_in_constant_time(): void
    {
        $migration = $this->migration();

        self::assertStringContainsString("\$table->char('state_hash', 64)", $migration);
        self::assertStringContainsString("\$table->char('items_hash', 64)", $migration);
        self::assertStringContainsString("\$table->unsignedBigInteger('item_count')", $migration);
        self::assertStringContainsString(
            'CREATE CONSTRAINT TRIGGER workforce_payroll_readiness_snapshots_complete',
            $migration,
        );
        self::assertStringNotContainsString(
            'CREATE CONSTRAINT TRIGGER workforce_payroll_readiness_snapshot_items_complete',
            $migration,
        );
        self::assertSame(1, substr_count($migration, 'DEFERRABLE INITIALLY DEFERRED'));
        self::assertSame(1, substr_count(
            $migration,
            'PERFORM workforce_payroll_readiness_assert_complete(NEW.id, NEW.sealed_at);',
        ));
        self::assertStringNotContainsString('COUNT(DISTINCT position)', $migration);
        self::assertStringNotContainsString('jsonb_agg(DISTINCT evidence_code ORDER BY evidence_code)', $migration);
        self::assertStringContainsString('undeclared_blocker_count', $migration);
        self::assertStringContainsString('missing_blocker_code_count', $migration);
        self::assertStringContainsString('IF snapshot.sealed_at IS NOT NULL THEN', $migration);
        self::assertStringContainsString('payroll readiness snapshot is already sealed', $migration);
        self::assertStringContainsString('payroll readiness item set incomplete', $migration);
    }

    public function test_database_contract_is_closed_to_v1_versions_reasons_and_policy(): void
    {
        $migration = $this->migration();

        self::assertStringContainsString("NEW.schema_version <> 'payroll-readiness-source.v1'", $migration);
        self::assertStringContainsString("NEW.formula_version <> 'payroll-readiness-checks.v1'", $migration);
        self::assertStringContainsString("NEW.policy_version <> 'payroll-readiness-policy.v1'", $migration);
        self::assertStringContainsString('NEW.reason_code NOT IN (', $migration);
        self::assertStringContainsString('NEW.source_row_count < 1', $migration);
        self::assertStringContainsString("NEW.policy_definition <> '", $migration);
        self::assertStringContainsString("snapshot.reason_code = 'source_empty'", $migration);
        self::assertStringContainsString("snapshot.reason_code IN ('validation_blockers', 'accounting_blockers')", $migration);
        self::assertStringContainsString('snapshot.blocker_codes ? evidence_code', $migration);
        self::assertStringContainsString('source_row.created_at > snapshot.evaluated_at', $migration);
        self::assertStringContainsString('validation_issue.created_at > snapshot.evaluated_at', $migration);

        $policy = PayrollReadinessPolicyDefinition::v1();
        self::assertStringContainsString($policy->hash(), $migration);
        self::assertStringContainsString(
            json_encode($policy->canonical(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $migration,
        );
    }

    private function migration(): string
    {
        $path = dirname(__DIR__, 5)
            .'/app/BusinessModules/Features/WorkforceManagement/migrations/'
            .'2026_08_01_000010_create_workforce_payroll_readiness_source.php';
        $contents = file_get_contents($path);
        self::assertIsString($contents);

        return $contents;
    }
}
