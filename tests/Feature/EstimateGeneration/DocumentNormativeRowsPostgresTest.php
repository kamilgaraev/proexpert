<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\EloquentGenerationPipelineDataGateway;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageException;
use App\BusinessModules\Addons\EstimateGeneration\Planning\WorkPlanCompiler;
use App\BusinessModules\Addons\EstimateGeneration\Services\ConstructionSemanticParser;
use App\BusinessModules\Addons\EstimateGeneration\Services\NormativeWorkItemPlannerService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class DocumentNormativeRowsPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_real_source_observation_is_included_once_across_compatible_house_packages(): void
    {
        [$context, $session] = $this->fixture();
        $session->input_payload = [...$session->input_payload, 'object_type' => 'house',
            'description' => 'Строительство жилого дома', 'generation_mode' => 'ai_assisted'];
        $session->save();
        $text = "ФЕР 08-02-001-01 Прокладка электрического кабеля 10 м\n\nФЕР 08-02-001-01 Прокладка электрического кабеля 20 м";
        $first = $this->document($session, $text);
        $second = $this->document($session, $text."\n");
        $source = app(EloquentGenerationPipelineDataGateway::class)->source($context);
        $analysis = app(ConstructionSemanticParser::class)->parse($source['input'], $source['documents']);
        $payload = app(WorkPlanCompiler::class)->compile($analysis, deferNormativePin: true);
        self::assertContains('electrical', array_column($payload['local_estimates'], 'key'));
        self::assertContains('lighting', array_column($payload['local_estimates'], 'key'));
        $references = [];
        foreach ($payload['local_estimates'] as $estimate) {
            foreach ($estimate['sections'] as $section) {
                foreach ($section['work_items'] as $item) {
                    if (($item['metadata']['generation_source'] ?? null) === 'project_document_normative_reference') {
                        $references[] = $item;
                    }
                }
            }
        }
        self::assertCount(4, $references);
        self::assertSame(['10', '20', '10', '20'], array_column($references, 'quantity'));
        self::assertSame([$first->id, $first->id, $second->id, $second->id], array_map(static fn (array $item): int => $item['source_refs'][0]['document_id'], $references));
        self::assertSame([1, 3, 1, 3], array_map(static fn (array $item): int => $item['source_refs'][0]['line_number'], $references));
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    public function test_real_gateway_parser_and_planner_preserve_both_document_rows_and_versions(): void
    {
        [$context, $session] = $this->fixture();
        $documents = [];
        foreach ([10, 20] as $quantity) {
            $documents[] = $this->document($session, 'ФЕР 08-02-001-01 Кладка стен '.$quantity.' м2');
        }
        [, $foreignSession] = $this->fixture();
        $this->document($foreignSession, str_repeat('x', EloquentGenerationPipelineDataGateway::MAX_SOURCE_BYTES + 1));

        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });
        $source = app(EloquentGenerationPipelineDataGateway::class)->source($context);
        self::assertSame(10, $queries);
        self::assertCount(2, $source['documents']);
        $analysis = app(ConstructionSemanticParser::class)->parse($source['input'], $source['documents']);
        $items = app(NormativeWorkItemPlannerService::class)->build(['key' => 'walls', 'scope_type' => 'walls'],
            ['key' => 'walls-section', 'construction_part' => 'walls'], $analysis);
        $references = array_values(array_filter($items, static fn (array $item): bool => ($item['metadata']['generation_source'] ?? null) === 'project_document_normative_reference'));
        self::assertCount(2, $references);
        self::assertSame(['10', '20'], array_column($references, 'quantity'));
        foreach ($references as $index => $item) {
            self::assertSame((int) $documents[$index]->id, $item['source_refs'][0]['document_id']);
            self::assertSame($documents[$index]->filename, $item['source_refs'][0]['filename']);
            self::assertSame($documents[$index]->source_version, $item['source_refs'][0]['source_version']);
            self::assertSame(1, $item['source_refs'][0]['line_number']);
            self::assertSame('quantity_review', $item['item_type']);
        }
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    public function test_oversized_text_is_rejected_without_truncation(): void
    {
        [$context, $session] = $this->fixture();
        $this->document($session, str_repeat('x', EloquentGenerationPipelineDataGateway::MAX_SOURCE_BYTES + 1));
        $this->expectException(PipelineStageException::class);
        app(EloquentGenerationPipelineDataGateway::class)->source($context);
    }

    public function test_combined_document_text_is_bounded_before_loading_the_source(): void
    {
        [$context, $session] = $this->fixture();
        $text = str_repeat('x', intdiv(EloquentGenerationPipelineDataGateway::MAX_SOURCE_BYTES, 2) + 1);
        $this->document($session, str_replace('x', 'y', $text));
        $this->document($session, $text);
        $this->expectException(PipelineStageException::class);
        app(EloquentGenerationPipelineDataGateway::class)->source($context);
    }

    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $actor = User::factory()->create();
        $attempt = (string) Str::uuid();
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id,
            'project_id' => $project->id, 'user_id' => $actor->id, 'state_version' => 1, 'status' => 'generating',
            'input_payload' => ['generation_attempt_id' => $attempt]]);

        return [new PipelineContext((int) $session->id, (int) $organization->id, (int) $project->id,
            1, 'sha256:'.str_repeat('a', 64), 'generating', generationAttemptId: $attempt), $session];
    }

    private function document(EstimateGenerationSession $session, string $text): EstimateGenerationDocument
    {
        return EstimateGenerationDocument::query()->create(['organization_id' => $session->organization_id,
            'project_id' => $session->project_id, 'session_id' => $session->id, 'user_id' => $session->user_id,
            'filename' => 'volumes-'.substr(hash('sha256', $text), 0, 8).'.pdf', 'mime_type' => 'application/pdf',
            'status' => 'ready', 'quality_level' => 'good', 'checksum_sha256' => hash('sha256', $text),
            'source_version' => 'sha256:'.hash('sha256', $text), 'extracted_text' => $text,
            'facts_summary' => ['document_understanding' => ['role_for_estimation' => 'quantity_source']]]);
    }
}
