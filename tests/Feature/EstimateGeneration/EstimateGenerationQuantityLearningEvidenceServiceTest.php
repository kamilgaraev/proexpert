<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationLearningExample;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\Learning\EstimateGenerationQuantityLearningEvidenceService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Tests\TestCase;

final class EstimateGenerationQuantityLearningEvidenceServiceTest extends TestCase
{
    public function test_hints_are_limited_to_trusted_current_document_quantity_keys(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $otherOrganization = Organization::factory()->create();
        $session = EstimateGenerationSession::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'status' => 'analyzed',
            'processing_stage' => 'object_analysis',
            'processing_progress' => 35,
            'input_payload' => [],
            'analysis_payload' => [],
            'draft_payload' => [],
            'problem_flags' => [],
        ]);

        $this->learningExample([
            'organization_id' => $organization->id,
            'project_id' => null,
            'norm_code' => 'quantity:rough.walls',
            'work_quantity' => 111.11,
            'accepted_at' => now(),
        ]);
        $projectExample = $this->learningExample([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'norm_code' => 'quantity:rough.walls',
            'work_quantity' => 218.25,
            'accepted_at' => now()->subDay(),
        ]);
        $this->learningExample([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'norm_code' => 'quantity:rough.walls',
            'work_quantity' => 999.0,
            'quality_flags' => ['low_quality'],
            'accepted_at' => now()->addDay(),
        ]);
        $this->learningExample([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'norm_code' => 'quantity:finish.floor',
            'work_quantity' => 50.0,
        ]);
        $this->learningExample([
            'organization_id' => $otherOrganization->id,
            'project_id' => null,
            'norm_code' => 'quantity:rough.walls',
            'work_quantity' => 444.0,
        ]);
        $this->learningExample([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'norm_code' => 'quantity:rough.walls',
            'work_quantity' => 777.0,
            'is_positive' => false,
        ]);

        $analysis = [
            'document_context' => [
                'quantity_takeoffs' => [[
                    'scope_key' => 'wall_finish_area',
                    'quantity' => 320.0,
                    'unit' => 'm2',
                ]],
            ],
        ];

        $service = app(EstimateGenerationQuantityLearningEvidenceService::class);
        $hints = $service->hintsForAnalysis((int) $organization->id, (int) $project->id, $analysis);

        $this->assertSame(['rough.walls'], array_keys($hints));
        $this->assertSame($projectExample->id, $hints['rough.walls']['learning_example_id']);
        $this->assertSame(218.25, $hints['rough.walls']['quantity']);
        $this->assertSame(2, $hints['rough.walls']['examples_count']);
        $this->assertTrue($hints['rough.walls']['same_project']);

        $enriched = $service->enrichAnalysis($session, $analysis);

        $this->assertSame(
            $projectExample->id,
            $enriched['document_context']['quantity_learning_hints']['rough.walls']['learning_example_id']
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function learningExample(array $overrides): EstimateGenerationLearningExample
    {
        return EstimateGenerationLearningExample::query()->create([
            'organization_id' => $overrides['organization_id'],
            'project_id' => $overrides['project_id'] ?? null,
            'source_type' => 'manual_quantity_confirmation',
            'source_entity_type' => 'estimate_generation_feedback',
            'source_entity_id' => random_int(1, 100000),
            'work_name' => 'Площадь стен',
            'work_unit' => 'm2',
            'work_quantity' => $overrides['work_quantity'] ?? 218.25,
            'work_intent' => [],
            'estimate_norm_id' => null,
            'norm_code' => $overrides['norm_code'] ?? 'quantity:rough.walls',
            'decision_status' => 'quantity_confirmed_by_user',
            'confidence' => $overrides['confidence'] ?? 1.0,
            'is_positive' => $overrides['is_positive'] ?? true,
            'source_quality_score' => $overrides['source_quality_score'] ?? 1.0,
            'context_payload' => [
                'quantity_key' => str_replace('quantity:', '', (string) ($overrides['norm_code'] ?? 'quantity:rough.walls')),
                'quantity_basis' => 'Проверено по планировке.',
                'calculation_basis' => 'wall_area_from_floor_plan',
            ],
            'source_refs' => [['type' => 'estimate_generation_feedback']],
            'quality_flags' => $overrides['quality_flags'] ?? ['user_confirmed_quantity'],
            'accepted_at' => $overrides['accepted_at'] ?? now(),
        ]);
    }
}
