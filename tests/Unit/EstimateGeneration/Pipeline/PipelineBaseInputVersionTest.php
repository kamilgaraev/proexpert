<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocumentFact;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDrawingElement;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationQuantityTakeoff;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationScopeInference;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineBaseInputVersion;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PipelineBaseInputVersionTest extends TestCase
{
    public function test_document_floor_identity_contract_has_its_own_cache_generation(): void
    {
        self::assertSame(8, PipelineBaseInputVersion::SCHEMA_VERSION);
    }

    public function test_schema_version_is_part_of_base_input_version(): void
    {
        $input = ['description' => 'Дом'];
        $documents = [[
            'id' => 10,
            'source_version' => 'sha256:'.str_repeat('a', 64),
            'status' => 'ready',
            'derived_version' => 'sha256:'.str_repeat('b', 64),
        ]];

        $expected = 'sha256:'.hash('sha256', CanonicalPipelineJson::encode([
            'schema_version' => PipelineBaseInputVersion::SCHEMA_VERSION,
            'input' => $input,
            'documents' => $documents,
            'project_model_snapshot_token' => null,
        ]));

        self::assertSame($expected, PipelineBaseInputVersion::fromProjection($input, $documents));
    }

    public function test_project_model_snapshot_changes_cache_version_independently_of_generation_attempt(): void
    {
        $documents = [[
            'id' => 10,
            'source_version' => 'sha256:'.str_repeat('a', 64),
            'status' => 'ready',
            'derived_version' => 'sha256:'.str_repeat('b', 64),
        ]];
        $selected = 'sha256:'.str_repeat('d', 64);

        $first = PipelineBaseInputVersion::fromProjection(
            ['description' => 'Дом', 'generation_attempt_id' => 'attempt-a'],
            $documents,
            $selected,
        );
        $retry = PipelineBaseInputVersion::fromProjection(
            ['description' => 'Дом', 'generation_attempt_id' => 'attempt-b'],
            $documents,
            $selected,
        );
        $replacement = PipelineBaseInputVersion::fromProjection(
            ['description' => 'Дом', 'generation_attempt_id' => 'attempt-b'],
            $documents,
            'sha256:'.str_repeat('e', 64),
        );
        $invalidated = PipelineBaseInputVersion::fromProjection(
            ['description' => 'Дом', 'generation_attempt_id' => 'attempt-b'],
            $documents,
            null,
        );

        self::assertSame($first, $retry);
        self::assertNotSame($first, $replacement);
        self::assertNotSame($first, $invalidated);
    }

    public function test_project_model_decision_changes_the_rebuild_input_through_snapshot_token(): void
    {
        $documents = [[
            'id' => 10,
            'source_version' => 'sha256:'.str_repeat('a', 64),
            'status' => 'ready',
            'derived_version' => 'sha256:'.str_repeat('b', 64),
        ]];
        self::assertNotSame(
            PipelineBaseInputVersion::fromProjection([], $documents, 'sha256:'.str_repeat('c', 64)),
            PipelineBaseInputVersion::fromProjection([], $documents, 'sha256:'.str_repeat('d', 64)),
        );
    }

    #[DataProvider('derivedMutations')]
    public function test_every_consumed_derived_source_changes_base_input_version(callable $mutate): void
    {
        $session = $this->session();
        $before = PipelineBaseInputVersion::fromSession($session);

        $mutate($session->documents->first());

        self::assertNotSame($before, PipelineBaseInputVersion::fromSession($session));
    }

    public static function derivedMutations(): iterable
    {
        yield 'structured payload' => [static fn (EstimateGenerationDocument $document) => $document->structured_payload = ['rooms' => 3]];
        yield 'facts summary' => [static fn (EstimateGenerationDocument $document) => $document->facts_summary = ['area' => 101]];
        yield 'quality score' => [static fn (EstimateGenerationDocument $document) => $document->quality_score = 0.4];
        yield 'quality level' => [static fn (EstimateGenerationDocument $document) => $document->quality_level = 'review'];
        yield 'quality flags' => [static fn (EstimateGenerationDocument $document) => $document->quality_flags = ['blurred']];
        yield 'fact' => [static fn (EstimateGenerationDocument $document) => $document->facts->first()->value_number = 101];
        yield 'drawing element' => [static fn (EstimateGenerationDocument $document) => $document->drawingElements->first()->geometry = ['x' => 2]];
        yield 'quantity takeoff' => [static fn (EstimateGenerationDocument $document) => $document->quantityTakeoffs->first()->quantity = 101];
        yield 'scope inference' => [static fn (EstimateGenerationDocument $document) => $document->scopeInferences->first()->description = 'changed'];
    }

    private function session(): EstimateGenerationSession
    {
        $document = new EstimateGenerationDocument([
            'status' => 'ready', 'checksum_sha256' => str_repeat('a', 64),
            'structured_payload' => ['rooms' => 2], 'facts_summary' => ['area' => 100],
            'quality_score' => 0.9, 'quality_level' => 'good', 'quality_flags' => [],
        ]);
        $document->id = 10;
        $fact = new EstimateGenerationDocumentFact(['fact_type' => 'area', 'value_number' => 100]);
        $fact->id = 1;
        $drawing = new EstimateGenerationDrawingElement(['type' => 'wall', 'geometry' => ['x' => 1]]);
        $drawing->id = 2;
        $takeoff = new EstimateGenerationQuantityTakeoff(['name' => 'wall', 'quantity' => 100]);
        $takeoff->id = 3;
        $scope = new EstimateGenerationScopeInference(['inference_type' => 'work', 'description' => 'initial']);
        $scope->id = 4;
        $document->setRelation('facts', new Collection([$fact]));
        $document->setRelation('drawingElements', new Collection([$drawing]));
        $document->setRelation('quantityTakeoffs', new Collection([$takeoff]));
        $document->setRelation('scopeInferences', new Collection([$scope]));
        $session = new EstimateGenerationSession(['input_payload' => ['description' => 'Дом']]);
        $session->setRelation('documents', new Collection([$document]));

        return $session;
    }
}
