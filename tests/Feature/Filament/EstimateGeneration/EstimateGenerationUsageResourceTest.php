<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\EstimateGeneration;

use App\Filament\Resources\EstimateGeneration\PipelineCheckpointResource;
use App\Filament\Resources\EstimateGeneration\UsageResource;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class EstimateGenerationUsageResourceTest extends TestCase
{
    public function test_resource_index_migration_is_online_idempotent_and_covers_each_standalone_filter_and_order(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_14_000300_add_estimate_generation_resource_indexes.php');
        self::assertIsString($source);
        self::assertStringContainsString('public $withinTransaction = false;', $source);

        $indexes = [
            'eg_usage_created_desc_idx' => 'estimate_generation_ai_usage (created_at DESC)',
            'eg_usage_requested_model_created_desc_idx' => 'estimate_generation_ai_usage (requested_model, created_at DESC)',
            'eg_usage_status_created_desc_idx' => 'estimate_generation_ai_usage (status, created_at DESC)',
            'eg_failure_identities_stage_idx' => 'estimate_generation_failure_identities (stage, id)',
            'eg_failure_identities_category_idx' => 'estimate_generation_failure_identities (category, id)',
            'eg_failure_occurrence_recorded_idx' => "estimate_generation_failure_events (recorded_at DESC, failure_id) WHERE event_type = 'occurred'",
            'eg_failure_resolution_lookup_idx' => "estimate_generation_failure_events (failure_id, resolves_through_sequence DESC) WHERE event_type = 'resolved'",
        ];
        foreach ($indexes as $name => $definition) {
            self::assertStringContainsString("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$name} ON {$definition}", $source);
            self::assertStringContainsString("DROP INDEX CONCURRENTLY IF EXISTS {$name}", $source);
        }

        $existing = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_000400_create_estimate_generation_ai_usage_table.php');
        $failures = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_000500_create_estimate_generation_failures_table.php');
        self::assertIsString($existing);
        self::assertIsString($failures);
        self::assertStringContainsString("index(['organization_id', 'session_id', 'created_at']", $existing);
        self::assertStringContainsString("index(['stage', 'status', 'created_at']", $existing);
        self::assertStringContainsString("index(['organization_id', 'session_id', 'created_at']", $failures);
    }

    public function test_usage_query_and_table_use_the_exact_safe_cost_allowlist(): void
    {
        self::assertSame([
            'attempt_id', 'organization_id', 'project_id', 'session_id', 'stage', 'operation',
            'attempt_ordinal', 'provider', 'requested_model', 'reported_model', 'usage_status',
            'status', 'input_tokens', 'cached_input_tokens', 'output_tokens', 'reasoning_tokens',
            'image_count', 'page_count', 'duration_ms', 'cost_amount', 'currency',
            'pricing_status', 'created_at',
        ], $this->safeColumns(UsageResource::class));

        $source = $this->source(UsageResource::class);
        self::assertStringContainsString("->with('session:id,organization_id,project_id,status')", $source);
        self::assertStringContainsString("->defaultSort('created_at', 'desc')", $source);
        foreach ([
            'session_id', 'provider', 'requested_model', 'stage', 'input_tokens',
            'cached_input_tokens', 'output_tokens', 'reasoning_tokens', 'image_count',
            'page_count', 'duration_ms', 'attempt_ordinal', 'status', 'cost_amount',
            'currency',
        ] as $column) {
            self::assertStringContainsString("TextColumn::make('{$column}')", $source);
        }
        foreach (['period', 'organization_id', 'requested_model', 'stage', 'status'] as $filter) {
            self::assertMatchesRegularExpression("/(?:Filter|SelectFilter)::make\\('{$filter}'\\)/", $source);
        }
        self::assertStringContainsString('paginationPageOptions([25, 50, 100])', $source);
    }

    public function test_usage_resource_is_monitor_only_and_read_only(): void
    {
        $source = $this->source(UsageResource::class);

        self::assertStringContainsString('FilamentPermission::ESTIMATE_GENERATION_MONITOR', $source);
        self::assertStringContainsString('public static function canCreate(): bool', $source);
        self::assertStringContainsString('public static function canEdit(Model $record): bool', $source);
        self::assertStringContainsString('public static function canDelete(Model $record): bool', $source);
        self::assertStringNotContainsString('ESTIMATE_GENERATION_OPERATE', $source);
        self::assertStringNotContainsString('DeleteAction::make', $source);
        self::assertStringNotContainsString('BulkAction', $source);
        self::assertStringNotContainsString('price_snapshot', strtolower($source));
    }

    public function test_checkpoint_resource_is_allowlisted_paginated_and_delegates_actions(): void
    {
        self::assertSame([
            'id', 'session_id', 'organization_id', 'project_id', 'generation_attempt_id',
            'stage', 'status', 'attempt_count', 'artifact_bytes', 'lease_expires_at',
            'started_at', 'completed_at', 'failed_at', 'invalidated_at',
            'invalidation_reason', 'last_error_code', 'created_at', 'updated_at',
        ], $this->safeColumns(PipelineCheckpointResource::class));

        $source = $this->source(PipelineCheckpointResource::class);
        self::assertStringContainsString("->with('session:id,organization_id,project_id,status,state_version')", $source);
        self::assertStringContainsString('OperateEstimateGenerationSession::class', $source);
        self::assertStringContainsString('AdminSessionOperation::Retry', $source);
        self::assertStringContainsString('AdminSessionOperation::Cancel', $source);
        self::assertStringContainsString('FilamentPermission::ESTIMATE_GENERATION_OPERATE', $source);
        self::assertStringContainsString('paginationPageOptions([25, 50, 100])', $source);
        foreach (['output_payload', 'metrics', 'warnings', 'claim_token', 'last_error_message', 'last_error_fingerprint'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, strtolower($source));
        }
        foreach (['->save()', '->update([', 'dispatch(', 'Bus::', 'Queue::'] as $mutation) {
            self::assertStringNotContainsString($mutation, $source);
        }
    }

    public function test_resources_follow_ai_estimator_navigation_contract(): void
    {
        self::assertSame(3, UsageResource::getNavigationSort());
        self::assertSame(5, PipelineCheckpointResource::getNavigationSort());

        foreach ([UsageResource::class, PipelineCheckpointResource::class] as $resource) {
            $source = $this->source($resource);
            self::assertStringContainsString('return NavigationGroups::aiEstimator();', $source);
        }
    }

    /** @param class-string $class */
    private function source(string $class): string
    {
        $source = file_get_contents((new ReflectionClass($class))->getFileName());
        self::assertIsString($source);

        return $source;
    }

    /** @param class-string $class
     * @return list<string>
     */
    private function safeColumns(string $class): array
    {
        $columns = (new ReflectionClass($class))->getMethod('safeColumns')->invoke(null);
        self::assertIsArray($columns);

        return $columns;
    }
}
