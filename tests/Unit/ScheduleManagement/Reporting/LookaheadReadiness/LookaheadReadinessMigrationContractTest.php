<?php

declare(strict_types=1);

namespace Tests\Unit\ScheduleManagement\Reporting\LookaheadReadiness;

use PHPUnit\Framework\TestCase;

final class LookaheadReadinessMigrationContractTest extends TestCase
{
    private string $migration;

    protected function setUp(): void
    {
        parent::setUp();
        $source = file_get_contents(
            dirname(__DIR__, 5)
            .'/app/BusinessModules/Features/ScheduleManagement/migrations/'
            .'2026_08_01_000040_create_lookahead_readiness_source.php',
        );
        self::assertIsString($source);
        $this->migration = $source;
    }

    public function test_forged_green_and_revoked_waiver_cannot_bypass_deterministic_evaluation(): void
    {
        self::assertStringContainsString('lookahead_readiness_expected_evaluation', $this->migration);
        self::assertStringContainsString("event_type = 'waiver_approved'", $this->migration);
        self::assertStringContainsString(
            "NEW.payload->'component_outcomes' IS DISTINCT FROM expected_evaluation->'component_outcomes'",
            $this->migration,
        );
        self::assertStringContainsString(
            "NEW.waiver_event_ids IS DISTINCT FROM expected_evaluation->'waiver_event_ids'",
            $this->migration,
        );
    }

    public function test_transition_tail_and_cross_aggregate_seal_races_share_database_locks(): void
    {
        self::assertSame(2, substr_count($this->migration, "'lookahead-task-event-stream:'"));
        self::assertStringContainsString('lookahead readiness prior event is not aggregate tail', $this->migration);
        self::assertStringContainsString("'lookahead-event-aggregate:'", $this->migration);
    }

    public function test_authorization_uses_current_role_definitions_and_denies_conditional_or_expired_grants(): void
    {
        self::assertStringContainsString('lookahead_system_role_definition_version_unique', $this->migration);
        self::assertStringContainsString('definition.canonical_definition = grant->\'role_definition\'', $this->migration);
        self::assertStringContainsString('assignment.expires_at > statement_timestamp()', $this->migration);
        self::assertStringContainsString('NOT EXISTS (', $this->migration);
        self::assertStringContainsString('FROM role_conditions condition', $this->migration);
        self::assertStringContainsString(
            "'lookahead_readiness_system_role_definitions',",
            $this->migration,
        );
        self::assertStringContainsString('BEFORE UPDATE OR DELETE', $this->migration);

        $authorizer = file_get_contents(
            dirname(__DIR__, 5)
            .'/app/BusinessModules/Features/ScheduleManagement/Reporting/LookaheadReadiness/Services/'
            .'LaravelLookaheadReadinessAuthorizer.php',
        );
        self::assertIsString($authorizer);
        self::assertStringContainsString('$conditions->isNotEmpty()', $authorizer);

        $store = file_get_contents(
            dirname(__DIR__, 5)
            .'/app/BusinessModules/Features/ScheduleManagement/Reporting/LookaheadReadiness/Services/'
            .'EloquentLookaheadReadinessSourceStore.php',
        );
        self::assertIsString($store);
        self::assertStringContainsString('assertCurrentSystemRoleDefinitions', $store);
        self::assertStringContainsString('getRoleUncached($roleSlug)', $store);
        self::assertStringContainsString(
            'lookahead_readiness_system_role_definition_revoked',
            $store,
        );
    }

    public function test_revision_lifecycle_and_sparse_json_are_database_authoritative(): void
    {
        self::assertStringContainsString('schedule_plan_revision_effective', $this->migration);
        self::assertStringContainsString('lookahead_commitment_effective', $this->migration);
        self::assertStringContainsString("NOT IN ('approved', 'superseded', 'withdrawn')", $this->migration);
        self::assertStringContainsString("NOT IN ('published', 'superseded', 'withdrawn')", $this->migration);
        self::assertStringContainsString('canonical_task ?& ARRAY[', $this->migration);
        self::assertStringContainsString('canonical_dependency ?& ARRAY[', $this->migration);
        self::assertStringContainsString('lookahead_readiness_policy_definition_valid', $this->migration);
        self::assertStringContainsString('schedule_plan_revision_lifecycle_validate', $this->migration);
        self::assertStringContainsString('lookahead_commitment_lifecycle_validate', $this->migration);
    }
}
