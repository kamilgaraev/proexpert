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
            'eg_failures_session_scope_fk',
            'eg_failures_document_scope_fk',
            'eg_failures_unit_scope_fk',
            'eg_failures_page_scope_fk',
            'eg_failures_category_ck',
            'eg_failures_safe_context_closed_ck',
            'eg_failures_safe_context_size_ck',
            'eg_failures_occurrence_ck',
            'eg_failures_timestamp_ck',
            'eg_failures_identity_uq',
            'estimate_generation_failure_occurrences',
            'eg_failure_occurrences_immutable_guard',
            'ON CONFLICT (event_id) DO NOTHING',
            'prevent_estimate_generation_failure_mutation',
            'resolve_estimate_generation_failure',
        ] as $required) {
            self::assertStringContainsString($required, $source);
        }
        foreach (['prompt', 'request', 'response', 'content', 'filename', 'path', 'authorization', 'api_key', 'token', 'secret'] as $forbidden) {
            self::assertStringContainsString("'$forbidden'", $source);
        }
    }

    #[Test]
    public function model_and_store_are_module_owned_and_store_implements_contract(): void
    {
        self::assertSame('estimate_generation_failures', (new EstimateGenerationFailure)->getTable());
        self::assertTrue((new ReflectionClass(EloquentFailureStore::class))->implementsInterface(FailureStore::class));
    }
}
