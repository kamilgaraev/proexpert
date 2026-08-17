<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\EloquentGenerationPipelineDataGateway;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageException;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\EstimateGeneration\EstimateGenerationPostgresTestCase;

#[Group('postgres-contract')]
final class PipelineSourceReadBudgetPostgresTest extends EstimateGenerationPostgresTestCase
{
    #[Test]
    public function source_query_count_is_constant_and_document_cap_rejects_without_truncation(): void
    {
        [$context, $session] = $this->fixture();
        $gateway = app(EloquentGenerationPipelineDataGateway::class);
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        self::assertSame([], $gateway->source($context)['documents']);
        self::assertCount(10, $queries);
        foreach ([
            'estimate_generation_sessions',
            'estimate_generation_documents',
            'estimate_generation_document_facts',
            'estimate_generation_drawing_elements',
            'estimate_generation_quantity_takeoffs',
            'estimate_generation_scope_inferences',
            'pg_advisory_xact_lock',
            'estimate_generation_project_model_fact_projections',
            'estimate_generation_project_model_conflicts',
            'estimate_generation_project_model_fact_projections',
        ] as $index => $source) {
            self::assertStringContainsString($source, strtolower($queries[$index]));
        }
        self::assertSame([], array_values(array_filter($queries, static fn (string $sql): bool => str_contains(strtolower($sql), 'string_agg') || str_contains(strtolower($sql), 'to_jsonb'))));

        $rows = [];
        for ($index = 1; $index <= EloquentGenerationPipelineDataGateway::MAX_DOCUMENTS + 1; $index++) {
            $rows[] = ['session_id' => $session->id, 'organization_id' => $session->organization_id,
                'project_id' => $session->project_id, 'user_id' => $session->user_id,
                'filename' => "drawing-{$index}.pdf", 'mime_type' => 'application/pdf',
                'checksum_sha256' => hash('sha256', (string) $index), 'created_at' => now(), 'updated_at' => now()];
        }
        EstimateGenerationDocument::query()->insert($rows);
        $this->expectException(PipelineStageException::class);
        $gateway->source($context);
    }

    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $user = User::factory()->create();
        $attempt = (string) Str::uuid();
        $session = EstimateGenerationSession::query()->create([
            'organization_id' => $organization->id, 'project_id' => $project->id, 'user_id' => $user->id,
            'status' => 'generating', 'processing_stage' => 'understand_documents', 'processing_progress' => 0,
            'input_payload' => ['generation_attempt_id' => $attempt], 'state_version' => 1,
        ]);
        $version = 'sha256:'.str_repeat('a', 64);

        return [new PipelineContext((int) $session->id, (int) $organization->id, (int) $project->id, 1,
            $version, 'generating', generationAttemptId: $attempt, baseInputVersion: $version), $session];
    }
}
