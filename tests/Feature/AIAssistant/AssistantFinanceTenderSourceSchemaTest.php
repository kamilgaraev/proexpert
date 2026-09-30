<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantFinanceTenderSourceSchema;
use App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantFinanceTenderMetadata;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AssistantFinanceTenderSourceSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_budgeting_safe_select_executes_against_all_seven_canonical_tables(): void
    {
        $definitions = AssistantFinanceTenderMetadata::entityDefinitions();
        $select = AssistantFinanceTenderMetadata::safeSelectColumns();
        foreach (['budget_article', 'budget_article_mapping', 'budget_line', 'budget_period', 'budget_scenario', 'responsibility_center', 'budget_version'] as $type) {
            $model = new $definitions[$type][1];
            $table = $model->getTable();
            self::assertSame([], array_values(array_diff($select[$type], Schema::getColumnListing($table))), $type);
            self::assertCount(0, DB::table($table)->select(array_map(static fn (string $field): string => $table.'.'.$field, $select[$type]))->limit(0)->get());
            self::assertSame('id', $model->getKeyName());
        }
        self::assertTrue(Schema::hasColumn('budget_versions', 'created_by'));
        self::assertContains('created_by', $select['budget_version']);
    }

    public function test_curated_source_proof_filters_persisted_legacy_body_before_limit(): void
    {
        $organization = Organization::withoutEvents(fn () => Organization::factory()->create());
        $legacy = RagSource::query()->create(['organization_id' => $organization->id, 'project_id' => null,
            'identity_part_key' => '', 'source_type' => 'budgeting', 'entity_type' => 'epm_data_mart_snapshot', 'entity_id' => '7',
            'title' => 'Старый финансовый снимок', 'checksum' => str_repeat('a', 64), 'source_version' => 'same-source-version',
            'metadata' => ['payload' => ['cfo_total' => '987654321.12']], 'indexed_at' => now()]);
        $current = RagSource::query()->create(['organization_id' => $organization->id, 'project_id' => null,
            'identity_part_key' => '', 'source_type' => 'budgeting', 'entity_type' => 'epm_data_mart_snapshot', 'entity_id' => '8',
            'title' => 'Безопасная карточка снимка', 'checksum' => str_repeat('b', 64), 'source_version' => 'same-source-version',
            'metadata' => [AssistantFinanceTenderSourceSchema::FIELD => AssistantFinanceTenderSourceSchema::revision('epm_data_mart_snapshot'), 'status' => 'ready'],
            'indexed_at' => now()]);
        $query = RagSource::query()->where('organization_id', $organization->id);
        AssistantFinanceTenderSourceSchema::apply($query, 'ai_rag_sources');
        self::assertSame([$current->id], $query->orderBy('id')->limit(1)->pluck('id')->all());
        self::assertFalse(AssistantFinanceTenderSourceSchema::allowsSource($legacy->toArray()));
        $legacy->update(['metadata' => [AssistantFinanceTenderSourceSchema::FIELD => AssistantFinanceTenderSourceSchema::revision('epm_data_mart_snapshot'), 'status' => 'ready']]);
        self::assertTrue(AssistantFinanceTenderSourceSchema::allowsSource($legacy->fresh()->toArray()));
        self::assertFalse(AssistantFinanceTenderSourceSchema::allowsReference('epm_data_mart_snapshot',
            ['source_id' => $legacy->id, 'content_scope' => 'unstructured', 'checksum' => str_repeat('a', 64)]));
    }
}
