<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Analysis;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\AiRoleRunRepository;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunClaim;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunFailure;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunResult;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Geometry\DeterministicGeometryCalculator;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Geometry\GeometryExpertInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Geometry\GeometryExpertModel;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Geometry\GeometrySheetRoleResolver;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Geometry\RunGeometryExpert;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Geometry\VisionGeometryExpertModel;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Role\AiAnalysisRole;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Contracts\VisionProvider;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionAnalysisData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionDocumentInput;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionEvidenceData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Preprocessing\ProjectiveTransformFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\EstimateGeneration\InMemoryProjectModelRepository;

final class GeometryExpertTest extends TestCase
{
    #[Test]
    public function geometry_ai_selects_allowlisted_claims_while_server_projects_values_units_and_lineage(): void
    {
        $sheet = $this->sheet('plan', 1, [[
            'quantity_ref' => 'floor-area',
            'entity_ref' => 'floor-1',
            'formula_id' => 'floor_area',
            'output_unit' => 'ft2',
            'rounding_scale' => 1,
            'operands' => [
                ['name' => 'length', 'claim_ref' => 'claim:length', 'evidence_ref' => 'evidence:length', 'value' => '999'],
                ['name' => 'width', 'claim_ref' => 'claim:width', 'evidence_ref' => 'evidence:width', 'value' => '999'],
            ],
        ]]);
        $sheet['document_id'] = 171;
        $sheet['page_id'] = 701;
        $sheet['processing_unit_id'] = 801;
        $sheet['source_version'] = 'sha256:'.str_repeat('a', 64);
        $sheet['arbitration'] = ['decisions' => [
            $this->geometryDecision('claim:length', '12.5', 'm', 'evidence:length'),
            $this->geometryDecision('claim:width', '8.4', 'm', 'evidence:width'),
        ]];

        $result = $this->calculator()->calculate($this->input([$sheet]));

        self::assertSame('105', $result->quantities[0]['value']);
        self::assertSame(['12.5', '8.4'], array_column($result->quantities[0]['operands'], 'value'));
        self::assertSame(['evidence:length', 'evidence:width'], $result->quantities[0]['evidence_ids']);
        self::assertSame(6, $result->quantities[0]['rounding_scale']);
    }

    #[Test]
    public function invalid_geometry_intent_is_quarantined_without_losing_valid_quantity(): void
    {
        $valid = $this->interpretation('floor:1:area', 'floor:1', 'floor_area', 'm2', [
            $this->operand('length', '12.5', 'm', 'length', 'page:1:length'),
            $this->operand('width', '8.4', 'm', 'width', 'page:1:width'),
        ]);
        $invalid = $this->interpretation('floor:2:area', 'floor:2', 'invented_formula', 'm2', [
            $this->operand('length', '10', 'm', 'length-2', 'page:2:length'),
        ]);

        $result = (new DeterministicGeometryCalculator(static fn (string $key): string => $key))->calculate(
            new GeometryExpertInput(7, 9, 11, 'sha256:'.str_repeat('a', 64), [
                $this->sheet('plan', 1, [$valid, $invalid]),
            ]),
        );

        self::assertCount(1, $result->quantities);
        self::assertSame('105', $result->quantities[0]['value']);
        self::assertStringStartsWith('quantity:', $result->quantities[0]['quantity_id']);
        self::assertNotSame('floor:1:area', $result->quantities[0]['quantity_id']);
        self::assertSame([[
            'sheet_id' => 'page:1',
            'index' => 1,
            'reason' => 'geometry_formula_invalid',
        ]], $result->quarantinedIntents);
    }

    #[Test]
    public function candidate_claims_cannot_be_promoted_to_confirmed_geometry(): void
    {
        $sheet = $this->sheet('plan', 1, [[
            'quantity_ref' => 'floor-area',
            'entity_ref' => 'floor-1',
            'formula_id' => 'floor_area',
            'operands' => [
                ['name' => 'length', 'claim_ref' => 'claim:length', 'evidence_ref' => 'evidence:length'],
                ['name' => 'width', 'claim_ref' => 'claim:width', 'evidence_ref' => 'evidence:width'],
            ],
        ]]);
        $sheet['arbitration'] = ['decisions' => [
            $this->geometryDecision('claim:length', '12.5', 'm', 'evidence:length', 'candidate'),
            $this->geometryDecision('claim:width', '8.4', 'm', 'evidence:width', 'candidate'),
        ]];

        $result = $this->calculator()->calculate($this->input([$sheet]));

        self::assertSame([], $result->quantities);
        self::assertSame('geometry_source_reference_not_allowlisted', $result->quarantinedIntents[0]['reason']);
    }

    #[Test]
    public function plan_dimensions_are_multiplied_as_exact_decimals_with_formula_and_evidence_lineage(): void
    {
        if (! class_exists(DeterministicGeometryCalculator::class) || ! class_exists(GeometryExpertInput::class)) {
            self::fail('Geometry expert contract is not implemented.');
        }

        $result = $this->calculator()->calculate(new GeometryExpertInput(
            organizationId: 7,
            projectId: 9,
            sessionId: 11,
            sourceVersion: 'sha256:'.str_repeat('a', 64),
            sheets: [[
                'sheet_id' => 'page:17',
                'sheet_role' => 'plan',
                'page_number' => 4,
                'interpretations' => [[
                    'quantity_id' => 'floor:1:area',
                    'entity_id' => 'floor:1',
                    'formula_id' => 'floor_area',
                    'output_unit' => 'm2',
                    'rounding_scale' => 6,
                    'operands' => [
                        ['name' => 'length', 'value' => '12.50', 'unit' => 'm', 'evidence_id' => 'evidence:101', 'physical_locator' => 'page:17:dimension:length'],
                        ['name' => 'width', 'value' => '8.40', 'unit' => 'm', 'evidence_id' => 'evidence:102', 'physical_locator' => 'page:17:dimension:width'],
                    ],
                ]],
            ]],
        ));

        self::assertCount(1, $result->quantities);
        self::assertSame('105', $result->quantities[0]['value']);
        self::assertSame('geometry-formulas:v2', $result->quantities[0]['formula_version']);
        self::assertSame(['evidence:101', 'evidence:102'], $result->quantities[0]['evidence_ids']);
        self::assertSame([], $result->questions);
    }

    #[Test]
    public function wall_and_roof_formulas_subtract_openings_without_float_arithmetic(): void
    {
        $result = $this->calculator()->calculate($this->input([
            $this->sheet('section', 5, [
                $this->interpretation('wall:1:net_area', 'wall:1', 'wall_net_area', 'm2', [
                    $this->operand('wall_length', '10', 'm', '201', 'wall:length'),
                    $this->operand('wall_height', '3', 'm', '202', 'wall:height'),
                    $this->operand('opening_width', '2', 'm', '203', 'opening:1:width'),
                    $this->operand('opening_height', '1', 'm', '204', 'opening:1:height'),
                    $this->operand('opening_width', '1.5', 'm', '205', 'opening:2:width'),
                    $this->operand('opening_height', '1.2', 'm', '206', 'opening:2:height'),
                ]),
            ]),
            $this->sheet('roof', 6, [
                $this->interpretation('roof:1:area', 'roof:1', 'sloped_roof_area', 'm2', [
                    $this->operand('plan_area', '100', 'm2', '207', 'roof:plan'),
                    $this->operand('slope_rise', '3', 'm', '208', 'roof:rise'),
                    $this->operand('slope_run', '4', 'm', '209', 'roof:run'),
                    $this->operand('roof_opening_area', '5', 'm2', '210', 'roof:opening'),
                ]),
            ]),
        ]));

        self::assertSame(['26.2', '120'], array_column($result->quantities, 'value'));
        self::assertSame(['wall_net_area', 'sloped_roof_area'], array_column($result->quantities, 'formula_id'));
    }

    #[Test]
    public function partial_opening_is_left_unresolved_instead_of_silently_ignored_or_crashing_the_role(): void
    {
        $result = $this->calculator()->calculate($this->input([
            $this->sheet('section', 5, [
                $this->interpretation('wall:1:net_area', 'wall:1', 'wall_net_area', 'm2', [
                    $this->operand('wall_length', '10', 'm', '211', 'wall:length'),
                    $this->operand('wall_height', '3', 'm', '212', 'wall:height'),
                    $this->operand('opening_width', '2', 'm', '213', 'opening:1:width'),
                ]),
            ]),
        ]));

        self::assertSame([], $result->quantities);
        self::assertSame('partial_opening_geometry', $result->conflicts[0]['code']);
        self::assertSame('Не определена высота одного из проёмов', $result->questions[0]['subject']);
        self::assertSame(5, $result->questions[0]['source_locator']['page_number']);
    }

    #[Test]
    public function decimal_boundary_is_rounded_once_after_exact_formula_evaluation(): void
    {
        $result = $this->calculator()->calculate($this->input([
            $this->sheet('plan', 4, [
                $this->interpretation('floor:decimal:area', 'floor:decimal', 'floor_area', 'm2', [
                    $this->operand('length', '0.333333333333', 'm', '221', 'decimal:length'),
                    $this->operand('width', '3', 'm', '222', 'decimal:width'),
                ]),
            ]),
        ]));

        self::assertSame('1', $result->quantities[0]['value']);
        self::assertSame(6, $result->quantities[0]['rounding_scale']);
    }

    #[Test]
    public function formula_operands_are_normalized_to_canonical_units_before_arithmetic(): void
    {
        $result = $this->calculator()->calculate($this->input([
            $this->sheet('plan', 4, [
                $this->interpretation('floor:metric:area', 'floor:metric', 'floor_area', 'm2', [
                    $this->operand('length', '1250', 'cm', '223', 'metric:length'),
                    $this->operand('width', '8400', 'mm', '224', 'metric:width'),
                ]),
            ]),
        ]));

        self::assertSame('105', $result->quantities[0]['value']);
        self::assertSame(['12.5', '8.4'], array_column($result->quantities[0]['operands'], 'value'));
        self::assertSame(['m', 'm'], array_column($result->quantities[0]['operands'], 'unit'));
    }

    #[Test]
    public function incompatible_operand_or_output_units_are_quarantined_before_arithmetic(): void
    {
        $result = $this->calculator()->calculate($this->input([
            $this->sheet('plan', 4, [
                $this->interpretation('floor:invalid:area', 'floor:invalid', 'floor_area', 'm', [
                    $this->operand('length', '12.5', 'm', '225', 'invalid:length'),
                    $this->operand('width', '8.4', 'm2', '226', 'invalid:width'),
                ]),
            ]),
        ]));

        self::assertSame([], $result->quantities);
        self::assertSame('geometry_unit_incompatible', $result->quarantinedIntents[0]['reason']);
    }

    #[Test]
    public function duplicated_physical_locator_is_not_counted_twice_and_creates_a_concrete_question(): void
    {
        $result = $this->calculator()->calculate($this->input([
            $this->sheet('plan', 4, [
                $this->interpretation('floor:1:area', 'floor:1', 'floor_area', 'm2', [
                    $this->operand('length', '10', 'm', '301', 'dimension:same'),
                    $this->operand('width', '8', 'm', '302', 'dimension:same'),
                ]),
            ]),
        ]));

        self::assertSame([], $result->quantities);
        self::assertSame('duplicate_physical_locator', $result->conflicts[0]['code']);
        self::assertSame('Повторно использован один и тот же размер', $result->questions[0]['subject']);
        self::assertSame(4, $result->questions[0]['source_locator']['page_number']);
    }

    #[Test]
    public function non_geometry_sheet_is_skipped_without_interpreting_or_calculating_it(): void
    {
        $result = $this->calculator()->calculate($this->input([
            $this->sheet('note', 7, [[
                'this_payload_must_not_be_read' => new \stdClass,
            ]]),
        ]));

        self::assertSame([], $result->quantities);
        self::assertSame(['page:7'], $result->skippedSheets);
        self::assertSame([], $result->questions);
    }

    #[Test]
    public function cross_sheet_quantity_disagreement_stays_unresolved_with_both_sources(): void
    {
        $result = $this->calculator()->calculate($this->input([
            $this->sheet('plan', 4, [
                $this->interpretation('floor:1:area', 'floor:1', 'floor_area', 'm2', [
                    $this->operand('length', '10', 'm', '401', 'plan:length'),
                    $this->operand('width', '8', 'm', '402', 'plan:width'),
                ]),
            ]),
            $this->sheet('explication', 9, [
                $this->interpretation('floor:1:area', 'floor:1', 'floor_area', 'm2', [
                    $this->operand('length', '10', 'm', '403', 'explication:length'),
                    $this->operand('width', '8.2', 'm', '404', 'explication:width'),
                ]),
            ]),
        ]));

        self::assertSame([], $result->quantities);
        self::assertSame('cross_sheet_geometry_conflict', $result->conflicts[0]['code']);
        self::assertSame([4, 9], $result->questions[0]['source_locator']['page_numbers']);
        self::assertSame('Площадь этажа различается между листами', $result->questions[0]['subject']);
    }

    #[Test]
    public function independently_calculated_pages_are_reconciled_as_one_source_set(): void
    {
        $calculator = $this->calculator();
        $first = $calculator->calculate($this->input([
            $this->sheet('plan', 4, [
                $this->interpretation('floor:1:area', 'floor:1', 'floor_area', 'm2', [
                    $this->operand('length', '10', 'm', '431', 'plan:length'),
                    $this->operand('width', '8', 'm', '432', 'plan:width'),
                ]),
            ]),
        ]));
        $second = $calculator->calculate($this->input([
            $this->sheet('explication', 9, [
                $this->interpretation('floor:1:area', 'floor:1', 'floor_area', 'm2', [
                    $this->operand('length', '10', 'm', '433', 'explication:length'),
                    $this->operand('width', '8.2', 'm', '434', 'explication:width'),
                ]),
            ]),
        ]));

        $result = $calculator->reconcileResults([
            ['result' => $first, 'document_id' => 31, 'page_id' => 41, 'page_number' => 4, 'source_version' => 'sha256:'.str_repeat('a', 64)],
            ['result' => $second, 'document_id' => 32, 'page_id' => 49, 'page_number' => 9, 'source_version' => 'sha256:'.str_repeat('b', 64)],
        ]);

        self::assertSame([], $result->quantities);
        self::assertSame('cross_sheet_geometry_conflict', $result->conflicts[0]['code']);
        self::assertSame([
            ['document_id' => 31, 'page_id' => 41, 'page_number' => 4, 'source_version' => 'sha256:'.str_repeat('a', 64)],
            ['document_id' => 32, 'page_id' => 49, 'page_number' => 9, 'source_version' => 'sha256:'.str_repeat('b', 64)],
        ], $result->questions[0]['source_locator']['sources']);
    }

    #[Test]
    public function arbiter_accepted_minority_evidence_controls_geometry_applicability(): void
    {
        $observers = [
            'observer_literal' => new AiRoleRunResult(['observation' => ['sheet_type' => 'detail']], 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'),
            'observer_construction' => new AiRoleRunResult(['observation' => ['sheet_type' => 'detail']], 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'),
            'observer_risk' => new AiRoleRunResult(['observation' => ['sheet_type' => 'roof_plan']], 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'),
        ];
        $arbitration = new AiRoleRunResult([
            'role' => 'arbiter',
            'decisions' => [[
                'claim_id' => 'risk:1',
                'status' => 'accepted',
                'supporting_claim_ids' => ['risk:1'],
            ]],
        ], 'dddddddd-dddd-4ddd-8ddd-dddddddddddd');

        self::assertSame('roof', (new GeometrySheetRoleResolver)->resolve($observers, $arbitration));
    }

    #[Test]
    public function geometry_role_is_persisted_once_and_exact_replay_does_not_call_the_model_again(): void
    {
        if (! class_exists(RunGeometryExpert::class) || ! interface_exists(GeometryExpertModel::class)) {
            self::fail('Persisted geometry role is not implemented.');
        }
        $runs = new GeometryRoleRunMemoryRepository;
        $models = new InMemoryProjectModelRepository;
        $this->seedGeometrySources($models);
        $model = new RecordedGeometryExpertModel([
            $this->sheet('plan', 4, [
                $this->interpretation('floor:1:area', 'floor:1', 'floor_area', 'm2', [
                    $this->operand('length', '10', 'm', '501', 'plan:length'),
                    $this->operand('width', '8', 'm', '502', 'plan:width'),
                ]),
            ]),
        ]);
        $service = new RunGeometryExpert($runs, $model, $this->calculator(), 'openai/gpt-5.6-luna');
        $input = $this->input([$this->sheet('plan', 4, [])]);

        $first = $service->run($input);
        $second = $service->run($input);

        self::assertSame($first->quantities, $second->quantities);
        self::assertSame(1, $model->calls);
        self::assertSame(AiAnalysisRole::GeometryExpert, $runs->inputs[0]->role);
        self::assertSame('geometry-expert:v1', $runs->inputs[0]->promptContractVersion);
        self::assertSame('80', $first->quantities[0]['value']);
        self::assertCount(0, $models->currentDerivedQuantities(7, 9, 11, 'sha256:'.str_repeat('a', 64)));
    }

    #[Test]
    public function vision_geometry_model_reads_applicable_original_sheets_and_preserves_arbitration_context(): void
    {
        if (! class_exists(VisionGeometryExpertModel::class)) {
            self::fail('Vision geometry model is not implemented.');
        }
        $provider = new RecordedGeometryVisionProvider([
            $this->interpretation('floor:1:area', 'floor:1', 'floor_area', 'm2', [
                $this->operand('length', '10', 'm', '601', 'plan:length'),
                $this->operand('width', '8', 'm', '602', 'plan:width'),
            ]),
        ]);
        $source = $this->visionInput();
        $input = $this->input([[
            'sheet_id' => 'page:17',
            'sheet_role' => 'plan',
            'page_number' => 4,
            'source' => $source,
            'arbitration' => ['fingerprint' => str_repeat('b', 64), 'decisions' => [['claim_id' => 'literal:1']]],
        ], $this->sheet('note', 7, [])]);
        $reserved = [];

        $sheets = (new VisionGeometryExpertModel($provider))->interpret(
            $input,
            static function (string $attemptId) use (&$reserved): void {
                $reserved[] = $attemptId;
            },
        );

        self::assertCount(1, $provider->inputs);
        self::assertSame($source->imageContent, $provider->inputs[0]->imageContent);
        self::assertSame('geometry-expert:v1', $provider->inputs[0]->auxiliaryMetadata['geometry_expert']['contract']);
        self::assertSame(str_repeat('b', 64), $provider->inputs[0]->auxiliaryMetadata['geometry_expert']['arbitration']['fingerprint']);
        self::assertSame('floor_area', $sheets[0]['interpretations'][0]['formula_id']);
        self::assertSame(['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'], $reserved);
    }

    #[Test]
    public function explicit_retry_lineages_change_geometry_physical_attempt_identity(): void
    {
        $firstProvider = new RecordedGeometryVisionProvider([]);
        $secondProvider = new RecordedGeometryVisionProvider([]);
        $sheet = static fn (VisionDocumentInput $source): array => [
            'sheet_id' => 'page:17',
            'sheet_role' => 'plan',
            'source' => $source,
            'arbitration' => ['decisions' => []],
        ];

        (new VisionGeometryExpertModel($firstProvider))->interpret(
            $this->input([$sheet($this->visionInput('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'))]),
            static function (): void {},
        );
        (new VisionGeometryExpertModel($secondProvider))->interpret(
            $this->input([$sheet($this->visionInput('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'))]),
            static function (): void {},
        );

        $first = $firstProvider->inputs[0]->operationContext;
        $second = $secondProvider->inputs[0]->operationContext;
        self::assertSame($first->correlationId, $second->correlationId);
        self::assertNotSame($first->attemptId, $second->attemptId);
        self::assertSame('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $first->processingLineageId);
        self::assertSame('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', $second->processingLineageId);
    }

    /** @param list<array<string,mixed>> $sheets */
    private function input(array $sheets): GeometryExpertInput
    {
        return new GeometryExpertInput(7, 9, 11, 'sha256:'.str_repeat('a', 64), $sheets);
    }

    /** @param list<array<string,mixed>> $interpretations @return array<string,mixed> */
    private function sheet(string $role, int $page, array $interpretations): array
    {
        return [
            'sheet_id' => 'page:'.$page,
            'sheet_role' => $role,
            'page_number' => $page,
            'interpretations' => $interpretations,
        ];
    }

    /** @param list<array<string,string>> $operands @return array<string,mixed> */
    private function interpretation(string $quantityId, string $entityId, string $formulaId, string $unit, array $operands): array
    {
        return [
            'quantity_id' => $quantityId,
            'entity_id' => $entityId,
            'formula_id' => $formulaId,
            'output_unit' => $unit,
            'rounding_scale' => 6,
            'operands' => $operands,
        ];
    }

    /** @return array<string,string> */
    private function operand(string $name, string $value, string $unit, string $evidence, string $locator): array
    {
        return [
            'name' => $name,
            'fact_id' => 'fact:'.$evidence,
            'projection_version' => 1,
            'value' => $value,
            'unit' => $unit,
            'evidence_id' => 'evidence:'.$evidence,
            'physical_locator' => $locator,
        ];
    }

    private function visionInput(?string $processingLineageId = null): VisionDocumentInput
    {
        $image = imagecreatetruecolor(2, 2);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        ob_start();
        imagepng($image);
        $content = ob_get_clean();
        $content = is_string($content) ? $content : '';

        return new VisionDocumentInput(
            7, 9, 11, 13, 17, 4, 19,
            'sha256:'.str_repeat('a', 64),
            'sha256:'.hash('sha256', $content),
            'image/png',
            $content,
            'high',
            new AiOperationContext(
                '11111111-1111-5111-8111-111111111111',
                '22222222-2222-5222-8222-222222222222',
                7, 9, 11, 'checking_geometry', 'vision', 1, 13, 17, 19, $processingLineageId,
            ),
            (new ProjectiveTransformFactory)->identity(),
            sheetRole: 'plan',
        );
    }

    private function seedGeometrySources(InMemoryProjectModelRepository $models): void
    {
        $sourceVersion = 'sha256:'.str_repeat('a', 64);
        $evidence = [
            new Evidence('evidence:501', 7, 9, 11, $sourceVersion, 'artifact:17', 'drawing', 4, nativeReference: 'plan:length'),
            new Evidence('evidence:502', 7, 9, 11, $sourceVersion, 'artifact:17', 'drawing', 4, nativeReference: 'plan:width'),
        ];
        $models->saveSourceModel(
            [new Entity('floor:1', 7, 9, 11, $sourceVersion, 'quantity', 'floor:1')],
            [
                new Fact('fact:501', 7, 9, 11, $sourceVersion, 'floor:1', 'length', '10', 'm', 1.0, 'document', 'confirmed', ['evidence:501']),
                new Fact('fact:502', 7, 9, 11, $sourceVersion, 'floor:1', 'width', '8', 'm', 1.0, 'document', 'confirmed', ['evidence:502']),
            ],
            $evidence,
        );
    }

    private function calculator(): DeterministicGeometryCalculator
    {
        $messages = require dirname(__DIR__, 4).'/lang/ru/estimate_generation.php';

        return new DeterministicGeometryCalculator(static function (string $key) use ($messages): string {
            $value = $messages;
            foreach (array_slice(explode('.', $key), 1) as $segment) {
                $value = is_array($value) ? ($value[$segment] ?? null) : null;
            }

            return is_string($value) ? $value : $key;
        });
    }

    /** @return array<string,mixed> */
    private function geometryDecision(string $claimId, string $value, string $unit, string $evidence, string $status = 'accepted'): array
    {
        return [
            'status' => $status,
            'evidence_refs' => [$evidence],
            'canonical_claim' => [
                'entity_key' => 'floor-1',
                'fact_type' => 'dimension',
                'value' => ['type' => 'decimal', 'data' => $value],
                'unit' => $unit,
                'source_claim_id' => $claimId,
            ],
        ];
    }
}

final class RecordedGeometryExpertModel implements GeometryExpertModel
{
    public int $calls = 0;

    public function __construct(private readonly array $sheets) {}

    public function interpret(GeometryExpertInput $input, callable $onPhysicalAttemptReserved): array
    {
        $this->calls++;
        $onPhysicalAttemptReserved('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');

        return $this->sheets;
    }
}

final class GeometryRoleRunMemoryRepository implements AiRoleRunRepository
{
    /** @var list<AiRoleRunInput> */
    public array $inputs = [];

    private ?AiRoleRunResult $result = null;

    public function claim(AiRoleRunInput $input, string $ownerUuid): AiRoleRunClaim
    {
        $this->inputs[] = $input;

        return $this->result === null
            ? new AiRoleRunClaim(1, 'owned', $ownerUuid)
            : new AiRoleRunClaim(1, 'replay', result: $this->result);
    }

    public function startPhysicalAttempt(int $runId, string $ownerUuid, string $physicalAttemptId): void {}

    public function complete(int $runId, string $ownerUuid, AiRoleRunResult $result): void
    {
        $this->result = $result;
    }

    public function fail(int $runId, string $ownerUuid, AiRoleRunFailure $failure): void {}

    public function loadCurrent(AiRoleRunInput $input): ?AiRoleRunClaim
    {
        return null;
    }

    public function completedFingerprints(int $organizationId, int $projectId, int $sessionId, array $roles, array $sourceVersions): array
    {
        return [];
    }
}

final class RecordedGeometryVisionProvider implements VisionProvider
{
    /** @var list<VisionDocumentInput> */
    public array $inputs = [];

    public function __construct(private readonly array $interpretations) {}

    public function analyze(VisionDocumentInput $input): VisionAnalysisData
    {
        $this->inputs[] = $input;
        ($input->onPhysicalAttemptReserved)('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');

        return new VisionAnalysisData(
            'floor_plan',
            [VisionEvidenceData::fromArray(['key' => 'page', 'locator' => [
                'page_id' => 17,
                'page_number' => 4,
                'processing_unit_id' => 19,
                'source_version' => 'sha256:'.str_repeat('a', 64),
                'coordinate_space' => 'normalized_derivative_v1',
            ]])],
            [],
            [],
            ['scale_missing'],
            'timeweb',
            'openai/gpt-5.6-luna',
            'openai/gpt-5.6-luna',
            'model:v1',
            'measured',
            1,
            1,
            rawObserverFacts: $this->interpretations,
        );
    }
}
