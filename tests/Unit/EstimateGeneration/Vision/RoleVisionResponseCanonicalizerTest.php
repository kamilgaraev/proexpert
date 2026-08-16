<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Vision;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionAnalysisData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionDocumentInput;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Preprocessing\ProjectiveTransformFactory;
use App\BusinessModules\Addons\EstimateGeneration\Vision\RoleVisionResponseCanonicalizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RoleVisionResponseCanonicalizerTest extends TestCase
{
    #[Test]
    public function observer_uses_local_evidence_references_while_server_owns_identifiers_and_locator(): void
    {
        $input = $this->input(['observer' => ['index' => 1]]);
        $payload = json_decode((string) file_get_contents(
            dirname(__DIR__, 3).'/Fixtures/EstimateGeneration/Vision/observer-production-v3.json',
        ), true, flags: JSON_THROW_ON_ERROR);

        $result = (new RoleVisionResponseCanonicalizer)->canonicalize($payload, $input);
        $evidenceId = $result->payload['evidence'][0]['key'];

        self::assertStringStartsWith('evidence:', $evidenceId);
        self::assertNotSame('dimension-line-1', $evidenceId);
        self::assertSame($this->locator(), $result->payload['evidence'][0]['locator']);
        self::assertSame($evidenceId, $result->payload['visual_attributes']['facade']['evidence_ref']);
        self::assertSame($evidenceId, $result->payload['project_sheet_analysis']['facts'][0]['evidenceRef']);
    }

    #[Test]
    public function arbiter_only_owns_intents_while_server_projects_the_transport_envelope(): void
    {
        $input = $this->input(['arbitration' => ['contract' => 'document-arbitration:v3']]);
        $payload = [
            'schema_version' => '3',
            'decisions' => [[
                'claim_id' => 'literal:1',
                'status' => 'unresolved',
                'supporting_claim_ids' => ['literal:1'],
                'evidence_refs' => ['literal:dimension-1'],
                'reason_code' => 'dimensioned_façade_evidence_missing',
                'question' => [
                    'code' => 'FACADE_DIMENSIONS_REQUIRED',
                    'subject' => 'Размеры фасада',
                    'reason' => 'Размерная цепочка отсутствует.',
                    'impact' => 'Площадь нельзя подтвердить.',
                    'recommendation' => 'Уточнить размеры.',
                    'choices' => [],
                    'source_locator' => ['page_id' => 999],
                ],
            ]],
        ];

        $result = (new RoleVisionResponseCanonicalizer)->canonicalize($payload, $input);

        self::assertSame(3, $result->payload['schema_version']);
        self::assertSame('unknown', $result->payload['sheet_type']);
        self::assertSame([], $result->payload['elements']);
        self::assertSame(['scale_missing'], $result->payload['warnings']);
        self::assertSame($payload['decisions'], $result->payload['project_sheet_analysis']['facts']);
        self::assertSame([
            'page_id' => 17,
            'page_number' => 4,
            'processing_unit_id' => 19,
            'source_version' => $this->version(),
            'coordinate_space' => 'normalized_derivative_v1',
        ], $result->payload['evidence'][0]['locator']);
    }

    #[Test]
    public function mismatched_outer_and_inner_evidence_keys_are_left_for_fail_closed_validation(): void
    {
        $payload = json_decode((string) file_get_contents(
            dirname(__DIR__, 3).'/Fixtures/EstimateGeneration/Vision/observer-production-v3.json',
        ), true, flags: JSON_THROW_ON_ERROR);
        $payload['evidence'] = [
            'outer-reference' => [
                'key' => 'inner-reference',
                'locator' => $this->locator(),
            ],
        ];

        $result = (new RoleVisionResponseCanonicalizer)->canonicalize(
            $payload,
            $this->input(['observer' => ['index' => 1]]),
        );

        self::assertSame($payload['evidence'], $result->payload['evidence']);
    }

    #[Test]
    public function duplicate_observer_evidence_is_isolated_without_rejecting_the_useful_response(): void
    {
        $payload = json_decode((string) file_get_contents(
            dirname(__DIR__, 3).'/Fixtures/EstimateGeneration/Vision/observer-production-v3.json',
        ), true, flags: JSON_THROW_ON_ERROR);
        $payload['evidence'][] = $payload['evidence'][0];

        $result = (new RoleVisionResponseCanonicalizer)->canonicalize(
            $payload,
            $this->input(['observer' => ['index' => 1]]),
        );

        self::assertCount(1, $result->payload['evidence']);
        self::assertSame(
            $result->payload['evidence'][0]['key'],
            $result->payload['project_sheet_analysis']['facts'][0]['evidenceRef'],
        );
    }

    #[Test]
    public function observer_preserves_only_the_explicit_evidence_classification_on_the_server_owned_locator(): void
    {
        $payload = json_decode((string) file_get_contents(
            dirname(__DIR__, 3).'/Fixtures/EstimateGeneration/Vision/observer-production-v3.json',
        ), true, flags: JSON_THROW_ON_ERROR);
        $payload['evidence'][0]['locator'] = [
            'page_id' => 999,
            'source_version' => 'sha256:'.str_repeat('f', 64),
            'signed_url' => 'https://untrusted.invalid/private',
            'explicit' => true,
        ];

        $result = (new RoleVisionResponseCanonicalizer)->canonicalize(
            $payload,
            $this->input(['observer' => ['index' => 1]]),
        );

        self::assertSame([
            ...$this->locator(),
            'explicit' => true,
        ], $result->payload['evidence'][0]['locator']);
        $analysis = VisionAnalysisData::fromProviderArray(
            $result->payload,
            'recorded',
            'openai/gpt-5.6-luna',
            'openai/gpt-5.6-luna',
            'recording:v1',
            'measured',
            1,
            1,
            64,
        );
        self::assertSame(true, $analysis->evidence[0]->locator['explicit'] ?? null);
    }

    #[Test]
    public function production_page_two_preserves_the_associative_element_and_nested_unit_fact(): void
    {
        $analysis = $this->analysisFromFixture('session-73-page-2-observer.json');

        self::assertCount(1, $analysis->elements);
        self::assertSame('text.title.ar', $analysis->elements[0]->key);
        self::assertSame('Архитектурные решения (АР)', $analysis->elements[0]->label);
        self::assertCount(1, $analysis->projectSheetAnalysis?->facts ?? []);
        self::assertSame([
            'type' => 'string',
            'data' => 'Архитектурные решения (АР)',
        ], $analysis->projectSheetAnalysis?->facts[0]['value'] ?? null);
        self::assertNull($analysis->projectSheetAnalysis?->facts[0]['unit'] ?? null);
        self::assertSame([], $analysis->quarantinedItems);
    }

    #[Test]
    public function production_page_three_preserves_all_twenty_six_facts_with_associative_evidence(): void
    {
        $analysis = $this->analysisFromFixture('session-73-page-3-observer.json');
        $facts = $analysis->projectSheetAnalysis?->facts ?? [];

        self::assertCount(26, $facts);
        self::assertSame('01 — Обложка', $facts[0]['value']['data'] ?? null);
        self::assertSame('Типовой индивидуальный одноэтажный жилой каркасно-панельный дом с деревянным каркасом', $facts[23]['value']['data'] ?? null);
        self::assertSame('ВСХ-70-АР', $facts[24]['value']['data'] ?? null);
        self::assertSame($this->version(), $analysis->evidence[0]->locator['source_version'] ?? null);
        self::assertSame([], $analysis->quarantinedItems);
    }

    #[Test]
    public function production_page_four_repairs_only_representation_and_quarantines_one_malformed_fact(): void
    {
        $analysis = $this->analysisFromFixture('session-73-page-4-observer.json');
        $facts = $analysis->projectSheetAnalysis?->facts ?? [];
        $byEntity = [];
        foreach ($facts as $fact) {
            $byEntity[$fact['entityKey']] = $fact;
        }

        self::assertSame('unknown', $analysis->sheetType);
        self::assertCount(29, $facts);
        self::assertSame(['type' => 'number', 'data' => 72.19], $byEntity['building_area_total']['value']);
        self::assertSame('m2', $byEntity['building_area_total']['unit']);
        self::assertSame(['type' => 'number', 'data' => 4.32], $byEntity['building_height']['value']);
        self::assertSame('m', $byEntity['building_height']['unit']);
        self::assertSame('pcs', $byEntity['above_ground_storeys']['unit']);
        self::assertSame('свайно-винтовой; также указана утепленная шведская плита (УШП)', $byEntity['foundation_type']['value']['data']);
        self::assertSame('доска сухая обрезная 42x142, шаг 0,58 м', $byEntity['external_wall_construction']['value']['data']);
        self::assertSame('двускатная', $byEntity['roof_form']['value']['data']);
        self::assertSame('битумная черепица', $byEntity['roof_covering']['value']['data']);
        self::assertSame(92.58, $byEntity['roof_area']['value']['data']);
        self::assertArrayNotHasKey('malformed_quantity', $byEntity);
        self::assertSame([
            ['section' => 'sheet_type', 'index' => 0, 'reason' => 'invalid_sheet_type'],
            ['section' => 'facts', 'index' => 29, 'reason' => 'invalid_project_sheet_value'],
        ], $analysis->quarantinedItems);
    }

    #[Test]
    public function production_representation_repair_rejects_a_conflicting_unit_without_affecting_other_facts(): void
    {
        $payload = json_decode((string) file_get_contents(
            dirname(__DIR__, 3).'/Fixtures/EstimateGeneration/Vision/session-73-page-4-observer.json',
        ), true, flags: JSON_THROW_ON_ERROR);
        $payload['project_sheet_analysis']['facts'][0]['unit'] = 'см';

        $canonical = (new RoleVisionResponseCanonicalizer)->canonicalize(
            $payload,
            $this->input(['observer' => ['index' => 1]]),
        );
        $analysis = VisionAnalysisData::fromProviderArray(
            $canonical->payload,
            'recorded',
            'openai/gpt-5.6-luna',
            'openai/gpt-5.6-luna',
            'recording:v1',
            'measured',
            1,
            1,
            100,
            100,
        );

        self::assertCount(28, $analysis->projectSheetAnalysis?->facts ?? []);
        self::assertNotContains(
            'building_dimensions',
            array_column($analysis->projectSheetAnalysis?->facts ?? [], 'entityKey'),
        );
        self::assertContains(
            ['section' => 'facts', 'index' => 0, 'reason' => 'invalid_project_sheet_value'],
            $analysis->quarantinedItems,
        );
    }

    private function analysisFromFixture(string $fixture): VisionAnalysisData
    {
        $payload = json_decode((string) file_get_contents(
            dirname(__DIR__, 3).'/Fixtures/EstimateGeneration/Vision/'.$fixture,
        ), true, flags: JSON_THROW_ON_ERROR);
        $canonical = (new RoleVisionResponseCanonicalizer)->canonicalize(
            $payload,
            $this->input(['observer' => ['index' => 1]]),
        );

        return VisionAnalysisData::fromProviderArray(
            $canonical->payload,
            'recorded',
            'openai/gpt-5.6-luna',
            'openai/gpt-5.6-luna',
            'recording:v1',
            'measured',
            1,
            1,
            100,
            100,
        );
    }

    /** @param array<string,mixed> $auxiliaryMetadata */
    private function input(array $auxiliaryMetadata): VisionDocumentInput
    {
        $image = imagecreatetruecolor(4, 4);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        ob_start();
        imagepng($image);
        $content = ob_get_clean();
        $content = is_string($content) ? $content : '';

        return new VisionDocumentInput(
            7, 9, 11, 13, 17, 4, 19, $this->version(),
            'sha256:'.hash('sha256', $content), 'image/png', $content, 'high',
            new AiOperationContext(
                '11111111-1111-5111-8111-111111111111',
                '22222222-2222-5222-8222-222222222222',
                7, 9, 11, 'understand_documents', 'vision', 1, 13, 17, 19,
            ),
            (new ProjectiveTransformFactory)->identity(),
            auxiliaryMetadata: $auxiliaryMetadata,
        );
    }

    /** @return array{page_id:int,page_number:int,processing_unit_id:int,source_version:string,coordinate_space:string} */
    private function locator(): array
    {
        return [
            'page_id' => 17,
            'page_number' => 4,
            'processing_unit_id' => 19,
            'source_version' => $this->version(),
            'coordinate_space' => 'normalized_derivative_v1',
        ];
    }

    private function version(): string
    {
        return 'sha256:'.str_repeat('a', 64);
    }
}
