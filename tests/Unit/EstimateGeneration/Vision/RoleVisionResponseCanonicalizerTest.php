<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Vision;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
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
