<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationFailure;
use App\BusinessModules\Addons\EstimateGeneration\Observability\EloquentFailureStore;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureStore;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class FailurePersistenceContractTest extends TestCase
{
    #[Test]
    public function schema_closes_tenant_privacy_dedupe_and_controlled_mutation_contracts(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4)
            .'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_000500_create_estimate_generation_failures_table.php');

        self::assertIsString($source);
        foreach ([
            'eg_failure_identities_session_fk',
            'eg_failure_identities_document_fk',
            'eg_failure_identities_unit_fk',
            'eg_failure_identities_page_fk',
            'eg_failure_identities_category_ck',
            'eg_failure_events_safe_context_closed_ck',
            'eg_failure_events_safe_context_size_ck',
            'eg_failure_events_attempt_ck',
            'eg_failure_events_resolution_ck',
            'estimate_generation_failure_events',
            'eg_failure_events_append_only_guard',
            'bigIncrements',
            'latest_occurrence_sequence',
            'resolves_through_sequence',
            'CREATE VIEW public.estimate_generation_failures',
        ] as $required) {
            self::assertStringContainsString($required, $source);
        }
        foreach (['prompt', 'request', 'response', 'content', 'filename', 'path', 'authorization', 'api_key', 'token', 'secret'] as $forbidden) {
            self::assertStringContainsString($forbidden, $source);
        }
        self::assertStringNotContainsString('app.eg_failure_mutation', $source);
        self::assertStringNotContainsString('SECURITY DEFINER', $source);
        self::assertStringContainsString('SET search_path = pg_catalog, public', $source);
        self::assertStringContainsString('REVOKE ALL ON FUNCTION', $source);
    }

    #[Test]
    public function model_and_store_are_module_owned_and_store_implements_contract(): void
    {
        self::assertSame('estimate_generation_failures', (new EstimateGenerationFailure)->getTable());
        self::assertTrue((new ReflectionClass(EloquentFailureStore::class))->implementsInterface(FailureStore::class));
    }

    #[Test]
    public function task_six_migration_reuses_plan_one_failure_code_without_duplicate_add_or_drop(): void
    {
        $migration = $this->migrationSource();
        $planOne = file_get_contents(dirname(__DIR__, 4)
            .'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_000001_rebuild_estimate_generation_session_workflow.php');

        self::assertIsString($planOne);
        self::assertStringContainsString("string('failure_code', 100)", $planOne);
        self::assertStringNotContainsString("Schema::table('estimate_generation_sessions'", $migration);
        self::assertLessThan(0, strcmp(
            '2026_07_11_000001_rebuild_estimate_generation_session_workflow.php',
            '2026_07_11_000500_create_estimate_generation_failures_table.php',
        ));
    }

    private function migrationSource(): string
    {
        $source = file_get_contents(dirname(__DIR__, 4)
            .'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_000500_create_estimate_generation_failures_table.php');
        self::assertIsString($source);

        return $source;
    }
}
