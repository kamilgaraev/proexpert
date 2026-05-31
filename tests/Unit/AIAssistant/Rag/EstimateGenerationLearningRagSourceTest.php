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
use Tests\TestCase;

final class EstimateGenerationLearningRagSourceTest extends TestCase
{
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
        return [1.0, 0.0, 0.0];
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
        return 3;
    }
}
