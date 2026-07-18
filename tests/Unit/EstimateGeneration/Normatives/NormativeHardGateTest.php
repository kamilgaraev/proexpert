<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeCandidateData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\WorkIntentData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeHardGate;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NormativeHardGateTest extends TestCase
{
    #[DataProvider('incompatibilities')]
    public function test_each_closed_compatibility_gate_rejects_candidate(string $field, mixed $value, string $reason): void
    {
        $candidate = $this->candidate([$field => $value]);
        $set = (new NormativeHardGate)->filter($this->intent(), [$candidate]);

        self::assertSame([], $set->candidates);
        self::assertSame([$reason], $set->rejected[0]->reasonCodes);
    }

    public static function incompatibilities(): array
    {
        return [
            'unit' => ['canonicalUnit', 'м3', 'unit_mismatch'],
            'dimension' => ['unitDimension', 'volume', 'unit_dimension_mismatch'],
            'material' => ['material', 'бетон', 'material_mismatch'],
            'technology' => ['technology', 'монолитная', 'technology_mismatch'],
            'structure' => ['structure', 'фундамент', 'structure_mismatch'],
            'section' => ['normativeSection', '06', 'normative_section_mismatch'],
            'object' => ['objectType', 'warehouse', 'object_type_mismatch'],
            'version' => ['datasetVersion', 'v2', 'dataset_version_mismatch'],
            'status' => ['datasetStatus', 'draft', 'dataset_status_mismatch'],
            'region' => ['regionCode', '77', 'region_mismatch'],
        ];
    }

    public function test_unknown_required_compatibility_data_is_rejected_closed(): void
    {
        $set = (new NormativeHardGate)->filter($this->intent(), [$this->candidate(['canonicalUnit' => null])]);

        self::assertSame(['unit_unknown'], $set->rejected[0]->reasonCodes);
    }

    public function test_scaled_compatible_unit_and_missing_optional_catalog_metadata_are_accepted(): void
    {
        $intent = new WorkIntentData(
            1, 2, 3, 'work-1', 'Разработка грунта под фундаменты', 'm3', 'volume', '',
            'excavation', 'foundation', '01', 'residential', 'v1', 'published', '16',
            new DateTimeImmutable('2026-01-01'), ['doc:1'],
        );
        $candidate = $this->candidate([
            'name' => 'Разработка грунта экскаваторами под фундаменты',
            'canonicalUnit' => '1000 м3', 'unitDimension' => null, 'material' => null,
            'technology' => null, 'structure' => null, 'normativeSection' => null,
            'objectType' => 'residential', 'regionCode' => null, 'validFrom' => null,
        ]);

        $set = (new NormativeHardGate)->filter($intent, [$candidate]);

        self::assertSame(['candidate-1'], array_map(static fn ($row): string => $row->id, $set->candidates));
        self::assertSame([], $set->rejected);
    }

    public function test_normative_subsection_is_compatible_with_preferred_section_prefix(): void
    {
        $set = (new NormativeHardGate)->filter($this->intent(), [$this->candidate([
            'normativeSection' => '08-01',
        ])]);

        self::assertSame(['candidate-1'], array_map(static fn ($row): string => $row->id, $set->candidates));
    }

    public function test_equivalent_numeric_section_formats_are_compatible(): void
    {
        $set = (new NormativeHardGate)->filter($this->intent(), [$this->candidate([
            'normativeSection' => '8.1',
        ])]);

        self::assertSame(['candidate-1'], array_map(static fn ($row): string => $row->id, $set->candidates));
    }

    public function test_candidate_must_belong_to_one_of_all_allowed_sections(): void
    {
        $intent = new WorkIntentData(
            1, 2, 3, 'foundation.concrete', 'Бетонирование фундаментов', 'm3', 'volume', '',
            '', '', '', 'residential', 'v1', 'published', '78',
            new DateTimeImmutable('2026-01-01'), ['doc:1'], ['01', '06'],
        );

        $set = (new NormativeHardGate)->filter($intent, [
            $this->candidate(['id' => 'allowed', 'canonicalUnit' => 'm3', 'unitDimension' => 'volume', 'normativeSection' => '06-01']),
            $this->candidate(['id' => 'foreign', 'canonicalUnit' => 'm3', 'unitDimension' => 'volume', 'normativeSection' => '09-01']),
        ]);

        self::assertSame(['allowed'], array_map(static fn ($row): string => $row->id, $set->candidates));
        self::assertSame(['normative_section_mismatch'], $set->rejected[0]->reasonCodes);
    }

    public function test_house_and_residential_object_types_are_compatible(): void
    {
        $intent = new WorkIntentData(
            1, 2, 3, 'work-1', 'Кладка стены', 'м2', 'area', 'кирпич', 'кладка', 'стена',
            '08', 'house', 'v1', 'published', '78', new DateTimeImmutable('2026-01-01'), ['doc:1'],
        );

        $set = (new NormativeHardGate)->filter($intent, [$this->candidate(['objectType' => 'residential'])]);

        self::assertSame(['candidate-1'], array_map(static fn ($row): string => $row->id, $set->candidates));
    }

    public function test_mixed_office_warehouse_object_accepts_both_zone_norm_types(): void
    {
        $intent = new WorkIntentData(
            1, 2, 3, 'work-1', 'Монтаж вентиляции', 'м2', 'area', '', 'installation',
            'engineering', '20', 'mixed_warehouse_office', 'v1', 'published', '78',
            new DateTimeImmutable('2026-01-01'), ['doc:1'],
        );
        $base = [
            'material' => null, 'technology' => null, 'structure' => null,
            'normativeSection' => '20-01',
        ];

        $set = (new NormativeHardGate)->filter($intent, [
            $this->candidate([...$base, 'id' => 'office', 'objectType' => 'office']),
            $this->candidate([...$base, 'id' => 'warehouse', 'objectType' => 'warehouse']),
        ]);

        self::assertSame(['office', 'warehouse'], array_map(static fn ($row): string => $row->id, $set->candidates));
    }

    public function test_combined_reasons_and_evidence_are_retained(): void
    {
        $set = (new NormativeHardGate)->filter($this->intent(), [$this->candidate([
            'canonicalUnit' => 'м3', 'material' => 'бетон',
        ])]);

        self::assertSame(['unit_mismatch', 'material_mismatch'], $set->rejected[0]->reasonCodes);
        self::assertSame('candidate-1', $set->rejected[0]->candidate->id);
        self::assertNotEmpty($set->rejected[0]->evidence);
    }

    #[DataProvider('semanticIncompatibilities')]
    public function test_semantically_foreign_normative_is_rejected_before_reranking(
        string $work,
        string $action,
        string $candidateName,
    ): void {
        $intent = new WorkIntentData(
            1, 2, 3, 'work-semantic', $work, 'm3', 'volume', '', $action, '', '',
            'residential', 'v1', 'published', '78', new DateTimeImmutable('2026-01-01'), ['doc:1'],
        );
        $candidate = $this->candidate([
            'name' => $candidateName,
            'canonicalUnit' => 'm3',
            'unitDimension' => 'volume',
            'material' => null,
            'technology' => null,
            'structure' => null,
            'normativeSection' => null,
            'objectType' => null,
        ]);

        $set = (new NormativeHardGate)->filter($intent, [$candidate]);

        self::assertSame([], $set->candidates);
        self::assertContains('semantic_mismatch', $set->rejected[0]->reasonCodes);
    }

    public static function semanticIncompatibilities(): array
    {
        return [
            'temporary fence is not grounding' => [
                'Устройство временного ограждения строительной площадки',
                'fence_installation',
                'Прокладка заземляющего проводника открыто по строительным основаниям',
            ],
            'foundation concrete is not reactor work' => [
                'Бетонирование ленточного фундамента',
                'concreting',
                'Устройство строительных конструкций атомного реактора',
            ],
            'backfill is not excavation' => [
                'Обратная засыпка пазух фундамента',
                'backfill',
                'Разработка грунта экскаваторами в котлованах',
            ],
            'wall masonry is not clay insulation' => [
                'Кладка наружных стен из газобетонных блоков',
                'masonry',
                'Боковая изоляция стен и фундаментов глиной',
            ],
        ];
    }

    public function test_semantically_matching_normative_remains_available_for_reranking(): void
    {
        $intent = new WorkIntentData(
            1, 2, 3, 'work-semantic', 'Кладка наружных стен из газобетонных блоков', 'm3', 'volume',
            '', 'masonry', '', '', 'residential', 'v1', 'published', '78',
            new DateTimeImmutable('2026-01-01'), ['doc:1'],
        );
        $candidate = $this->candidate([
            'name' => 'Кладка стен из газобетонных блоков',
            'canonicalUnit' => 'm3',
            'unitDimension' => 'volume',
            'material' => null,
            'technology' => null,
            'structure' => null,
            'normativeSection' => null,
            'objectType' => null,
        ]);

        $set = (new NormativeHardGate)->filter($intent, [$candidate]);

        self::assertSame(['candidate-1'], array_map(static fn ($row): string => $row->id, $set->candidates));
    }

    public function test_generic_normative_title_can_be_confirmed_by_its_work_composition(): void
    {
        $intent = new WorkIntentData(
            1, 2, 3, 'work-composition', 'Кладка наружных стен из газобетонных блоков', 'm3', 'volume',
            '', 'masonry', '', '', 'residential', 'v1', 'published', '78',
            new DateTimeImmutable('2026-01-01'), ['doc:1'],
        );
        $candidate = $this->candidate([
            'name' => 'Устройство конструкций здания',
            'canonicalUnit' => 'm3', 'unitDimension' => 'volume',
            'material' => null, 'technology' => null, 'structure' => null,
            'normativeSection' => null, 'objectType' => null,
            'workComposition' => ['Кладка наружных стен из газобетонных блоков'],
        ]);

        $set = (new NormativeHardGate)->filter($intent, [$candidate]);

        self::assertSame(['candidate-1'], array_map(static fn ($row): string => $row->id, $set->candidates));
    }

    public function test_explicitly_requested_normative_code_preserves_user_or_document_decision(): void
    {
        $intent = new WorkIntentData(
            1, 2, 3, 'work-explicit', 'Устройство временного ограждения', 'm', 'length',
            '', 'fence_installation', '', '', 'residential', 'v1', 'published', '78',
            new DateTimeImmutable('2026-01-01'), ['doc:1'], [], '09-01-001-01',
        );
        $candidate = $this->candidate([
            'code' => '09-01-001-01', 'name' => 'Специальная проектная норма',
            'canonicalUnit' => 'm', 'unitDimension' => 'length',
            'material' => null, 'technology' => null, 'structure' => null,
            'normativeSection' => null, 'objectType' => null,
        ]);

        $set = (new NormativeHardGate)->filter($intent, [$candidate]);

        self::assertSame(['candidate-1'], array_map(static fn ($row): string => $row->id, $set->candidates));
    }

    private function intent(): WorkIntentData
    {
        return new WorkIntentData(1, 2, 3, 'work-1', 'кладка стены', 'м2', 'area', 'кирпич', 'кладка', 'стена', '08', 'жилой', 'v1', 'published', '78', new DateTimeImmutable('2026-01-01'), ['doc:1']);
    }

    private function candidate(array $overrides = []): NormativeCandidateData
    {
        $values = array_replace([
            'id' => 'candidate-1', 'normativeId' => 10, 'datasetId' => 20, 'datasetVersion' => 'v1',
            'datasetStatus' => 'published', 'code' => '08-01-001-01', 'name' => 'Кладка стен',
            'canonicalUnit' => 'м2', 'unitDimension' => 'area', 'material' => 'кирпич',
            'technology' => 'кладка', 'structure' => 'стена', 'normativeSection' => '08',
            'objectType' => 'жилой', 'regionCode' => '78', 'validFrom' => new DateTimeImmutable('2025-01-01'),
            'validTo' => null, 'lexicalScore' => 0.8, 'semanticScore' => 0.7,
            'lexicalAlgorithmVersion' => 'lex-v1', 'semanticIndexVersion' => 'sem-v1',
            'sourceEvidence' => ['norm:10'],
        ], $overrides);

        return new NormativeCandidateData(...$values);
    }
}
