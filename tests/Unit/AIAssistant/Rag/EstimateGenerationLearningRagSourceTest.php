<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Rag;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationLearningExample;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceRegistry;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\EstimateGenerationLearningRagSource;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Tests\Support\RagTestEmbedding;
use Tests\TestCase;

final class EstimateGenerationLearningRagSourceTest extends TestCase
{
    public function test_current_chunks_batch_uses_current_policy_and_the_existing_serializer(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $examples = [];
        foreach (range(1, 12) as $id) {
            $examples[] = EstimateGenerationLearningExample::query()->create([
                'organization_id' => $organization->id, 'source_type' => 'user_rejection',
                'source_entity_type' => 'estimate_item', 'source_entity_id' => $id,
                'work_name' => 'Отвергнутая позиция '.$id, 'work_unit' => 'count',
                'norm_code' => '20-99-'.$id, 'is_positive' => false, 'decision_status' => 'rejected',
                'quality_flags' => ['unit_compatible'], 'source_quality_score' => $id % 2 === 0 ? 1 : 0.85,
            ]);
        }
        $examples[0]->update(['quality_flags' => ['do_not_index']]);
        $examples[1]->update(['quality_flags' => ['unit_unverified']]);
        $examples[2]->update(['source_type' => 'ai_generated_estimate']);
        $examples[3]->update(['organization_id' => $otherOrganization->id]);
        $examples[4]->delete();
        $source = app(EstimateGenerationLearningRagSource::class);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $chunks = $source->currentChunks($organization->id, array_column($examples, 'id'));
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        self::assertCount(1, $queries);
        self::assertSame(array_map('strval', array_column(array_slice($examples, 5), 'id')), array_map('strval', array_keys($chunks)));
        foreach (array_slice($examples, 5) as $example) {
            $legacy = collect($source->collectEntity($organization->id, 'estimate_generation_learning_example', $example->id))->first();
            self::assertEquals($legacy, $chunks[(string) $example->id]);
            self::assertFalse($chunks[(string) $example->id]->metadata['is_positive']);
        }
        $indexer = new RagIndexer(new LearningRagEmbeddingProvider, new RagSourceRegistry([]));
        $last = $examples[11];
        $current = $chunks[(string) $last->id];
        $oldMetadata = [...$current->metadata, 'source_quality_score' => 1.0];
        self::assertSame(json_encode($oldMetadata), json_encode($current->metadata));
        $indexer->indexChunk(new \App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagChunkData(
            $current->organizationId, $current->projectId, $current->sourceType, $current->entityType, $current->entityId,
            $current->title, $current->content, $oldMetadata, $current->updatedAt));
        $indexed = RagSource::query()->where('entity_id', (string) $last->id)->with('chunks')->firstOrFail();
        self::assertTrue($indexer->matchesLexicalSource($indexed, $current));
        $checksum = $indexed->checksum;
        $indexer->indexChunk($current);
        self::assertSame($checksum, $indexed->fresh()->checksum);
        self::assertSame(0.85, $chunks[(string) $examples[10]->id]->metadata['source_quality_score']);
    }

    public function test_collects_learning_examples_as_rag_chunks(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $example = EstimateGenerationLearningExample::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'source_type' => 'imported_estimate',
            'source_entity_type' => 'estimate_item',
            'source_entity_id' => 15,
            'work_name' => 'Бетонирование фундаментной ленты B22.5',
            'work_unit' => 'м3',
            'work_quantity' => 13.8,
            'work_intent' => [
                'scope' => 'foundation',
                'action' => 'concreting',
                'system' => null,
            ],
            'norm_code' => '01-01-006-01',
            'normative_name' => 'Бетонирование конструкций',
            'normative_unit' => 'м3',
            'decision_status' => 'imported_selected',
            'is_positive' => true,
            'confidence' => 0.9,
            'source_quality_score' => 0.85,
            'context_payload' => [
                'section_name' => 'Фундамент',
            ],
            'source_refs' => [[
                'type' => 'estimate_item',
                'estimate_item_id' => 15,
            ]],
            'quality_flags' => ['unit_compatible'],
        ]);

        $chunks = collect(app(EstimateGenerationLearningRagSource::class)->collectForOrganization(
            $organization->id,
            $project->id
        ))->values();

        $this->assertCount(1, $chunks);
        $chunk = $chunks[0];

        $this->assertSame('estimate_generation_learning', $chunk->sourceType);
        $this->assertSame('estimate_generation_learning_example', $chunk->entityType);
        $this->assertSame($example->id, $chunk->entityId);
        $this->assertStringContainsString('Бетонирование фундаментной ленты B22.5', $chunk->content);
        $this->assertStringContainsString('work_unit=м3', $chunk->content);
        $this->assertStringContainsString('scope=foundation', $chunk->content);
        $this->assertStringContainsString('action=concreting', $chunk->content);
        $this->assertStringContainsString('01-01-006-01', $chunk->content);
        $this->assertStringContainsString('Бетонирование конструкций', $chunk->content);
        $this->assertStringContainsString('decision_status=imported_selected', $chunk->content);
        $this->assertStringContainsString('evidence=positive', $chunk->content);
        $this->assertSame($example->id, $chunk->metadata['learning_example_id']);
        $this->assertSame('imported_estimate', $chunk->metadata['source_type']);
        $this->assertSame('01-01-006-01', $chunk->metadata['normative_code']);
        $this->assertSame(['scope' => 'foundation', 'action' => 'concreting', 'system' => null], $chunk->metadata['work_intent']);
        $this->assertTrue($chunk->metadata['is_positive']);
    }

    public function test_registry_contains_learning_source(): void
    {
        $registry = app(RagSourceRegistry::class);

        $this->assertContains('estimate_generation_learning', $registry->enabledSourceTypes());
    }

    public function test_collects_manual_quantity_confirmation_examples_as_rag_chunks(): void
    {
        $organization = Organization::factory()->create();
        $example = EstimateGenerationLearningExample::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'manual_quantity_confirmation',
            'source_entity_type' => 'estimate_generation_feedback',
            'source_entity_id' => 31,
            'work_name' => 'Площадь стен',
            'work_unit' => 'м2',
            'work_quantity' => 218.25,
            'work_intent' => [
                'scope' => 'rough',
                'action' => 'surface_preparation',
                'system' => null,
            ],
            'norm_code' => 'quantity:rough.walls',
            'decision_status' => 'quantity_confirmed_by_user',
            'is_positive' => true,
            'confidence' => 1.0,
            'source_quality_score' => 1.0,
            'context_payload' => [
                'section_title' => 'Стены',
                'quantity_key' => 'rough.walls',
            ],
            'source_refs' => [[
                'type' => 'document',
                'filename' => 'plan.pdf',
                'page_number' => 1,
            ]],
            'quality_flags' => ['user_confirmed_quantity', 'manual_quantity_review'],
        ]);

        $chunks = collect(app(EstimateGenerationLearningRagSource::class)->collectForOrganization($organization->id))->values();

        $this->assertCount(1, $chunks);
        $chunk = $chunks[0];

        $this->assertSame($example->id, $chunk->entityId);
        $this->assertStringContainsString('work_quantity=218.25', $chunk->content);
        $this->assertStringContainsString('normative_code=quantity:rough.walls', $chunk->content);
        $this->assertSame('manual_quantity_confirmation', $chunk->metadata['source_type']);
    }

    public function test_learning_source_does_not_collect_untrusted_generated_examples(): void
    {
        $organization = Organization::factory()->create();
        $example = EstimateGenerationLearningExample::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'ai_generated_estimate',
            'source_entity_type' => 'estimate_item',
            'source_entity_id' => 17,
            'work_name' => 'Бетонирование фундаментной ленты B22.5',
            'work_unit' => 'м3',
            'work_quantity' => 13.8,
            'work_intent' => [
                'scope' => 'foundation',
                'action' => 'concreting',
                'system' => null,
            ],
            'norm_code' => '01-01-006-01',
            'normative_name' => 'Бетонирование конструкций',
            'normative_unit' => 'м3',
            'decision_status' => 'generated',
            'is_positive' => true,
            'confidence' => 1.0,
            'source_quality_score' => 1.0,
            'context_payload' => [],
            'source_refs' => [],
            'quality_flags' => ['unit_compatible'],
        ]);
        $source = app(EstimateGenerationLearningRagSource::class);

        $chunks = collect($source->collectForOrganization($organization->id))->values();
        $entityChunks = collect($source->collectEntity(
            $organization->id,
            'estimate_generation_learning_example',
            $example->id
        ))->values();

        $this->assertCount(0, $chunks);
        $this->assertCount(0, $entityChunks);
    }

    public function test_learning_source_prunes_previously_indexed_untrusted_examples(): void
    {
        $organization = Organization::factory()->create();
        $example = EstimateGenerationLearningExample::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'ai_generated_estimate',
            'source_entity_type' => 'estimate_item',
            'source_entity_id' => 18,
            'work_name' => 'Бетонирование фундаментной ленты B22.5',
            'work_unit' => 'м3',
            'work_quantity' => 13.8,
            'work_intent' => [
                'scope' => 'foundation',
                'action' => 'concreting',
                'system' => null,
            ],
            'norm_code' => '01-01-006-01',
            'normative_name' => 'Бетонирование конструкций',
            'normative_unit' => 'м3',
            'decision_status' => 'generated',
            'is_positive' => true,
            'confidence' => 1.0,
            'source_quality_score' => 1.0,
            'context_payload' => [],
            'source_refs' => [],
            'quality_flags' => ['unit_compatible'],
        ]);
        $source = RagSource::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'estimate_generation_learning',
            'entity_type' => 'estimate_generation_learning_example',
            'entity_id' => (string) $example->id,
            'title' => 'stale generated learning',
            'checksum' => hash('sha256', 'stale'),
            'metadata' => ['source_type' => 'ai_generated_estimate'],
            'indexed_at' => now(),
        ]);
        $source->chunks()->create([
            'organization_id' => $organization->id,
            'chunk_index' => 0,
            'content' => 'stale generated learning',
            'content_hash' => hash('sha256', 'stale generated learning'),
            'metadata' => [],
            'embedding_provider' => 'fake',
            'embedding_model' => 'fake-model',
            'embedding_created_at' => now(),
        ]);

        $pruned = app(EstimateGenerationLearningRagSource::class)->pruneForOrganization($organization->id);

        $this->assertSame(1, $pruned);
        $this->assertFalse(RagSource::query()->whereKey($source->id)->exists());
        $this->assertSame(0, $source->chunks()->count());
    }

    public function test_learning_source_can_be_indexed_by_source_type(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $example = EstimateGenerationLearningExample::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'source_type' => 'imported_estimate',
            'source_entity_type' => 'estimate_item',
            'source_entity_id' => 16,
            'work_name' => 'Бетонирование фундаментной ленты B22.5',
            'work_unit' => 'м3',
            'work_quantity' => 13.8,
            'work_intent' => [
                'scope' => 'foundation',
                'action' => 'concreting',
                'system' => null,
            ],
            'norm_code' => '01-01-006-01',
            'normative_name' => 'Бетонирование конструкций',
            'normative_unit' => 'м3',
            'decision_status' => 'imported_selected',
            'is_positive' => true,
            'confidence' => 0.9,
            'source_quality_score' => 0.85,
            'context_payload' => [
                'section_name' => 'Фундамент',
            ],
            'source_refs' => [[
                'type' => 'estimate_item',
                'estimate_item_id' => 16,
            ]],
            'quality_flags' => ['unit_compatible'],
        ]);

        $indexed = (new RagIndexer(
            new LearningRagEmbeddingProvider,
            app(RagSourceRegistry::class)
        ))->indexOrganization($organization->id, $project->id, 'estimate_generation_learning');

        $this->assertSame(1, $indexed);
        $source = RagSource::query()->where('source_type', 'estimate_generation_learning')->firstOrFail();

        $this->assertSame((string) $example->id, $source->entity_id);
        $this->assertSame('estimate_generation_learning_example', $source->entity_type);
        $this->assertSame(1, $source->chunks()->count());
    }
}

final class LearningRagEmbeddingProvider implements RagEmbeddingProviderInterface
{
    public function embed(string $text, string $purpose = self::PURPOSE_DOCUMENT): array
    {
        return RagTestEmbedding::fromLeadingValues([1.0, 0.0, 0.0]);
    }

    public function provider(): string
    {
        return 'fake';
    }

    public function model(): string
    {
        return 'fake-model';
    }

    public function dimensions(): int
    {
        return RagTestEmbedding::DIMENSIONS;
    }
}
