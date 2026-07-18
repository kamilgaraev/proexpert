<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeIntentCandidateRanker;
use PHPUnit\Framework\TestCase;

final class NormativeIntentCandidateRankerTest extends TestCase
{
    public function test_residential_sewerage_prefers_internal_pipeline_norm(): void
    {
        $candidates = [
            $this->candidate(
                401,
                '16-04-001-01',
                'Прокладка трубопроводов канализации из полиэтиленовых труб высокой плотности диаметром: 50 мм',
            ),
            $this->candidate(
                404,
                '16-04-004-01',
                'Прокладка внутренних трубопроводов канализации из полипропиленовых труб диаметром: 50 мм',
            ),
        ];

        $selected = (new NormativeIntentCandidateRanker)->select($candidates, [[
            'search_text' => 'Прокладка труб канализации',
            'unit' => 'm',
            'action' => 'pipe_layout',
            'scope' => 'engineering',
            'system' => 'sewerage',
            'object_type' => 'residential',
            'normative_sections' => ['16'],
        ]]);

        self::assertNotNull($selected);
        self::assertSame(404, $selected[0]->id);
    }

    public function test_warehouse_does_not_receive_residential_internal_pipeline_priority(): void
    {
        $candidates = [
            $this->candidate(401, '16-04-001-01', 'Прокладка трубопроводов канализации диаметром: 50 мм'),
            $this->candidate(404, '16-04-004-01', 'Прокладка внутренних трубопроводов канализации диаметром: 50 мм'),
        ];

        $selected = (new NormativeIntentCandidateRanker)->select($candidates, [[
            'search_text' => 'Прокладка труб канализации',
            'unit' => 'm',
            'action' => 'pipe_layout',
            'scope' => 'engineering',
            'system' => 'sewerage',
            'object_type' => 'mixed_warehouse_office',
            'normative_sections' => ['16'],
        ]]);

        self::assertNotNull($selected);
        self::assertSame(401, $selected[0]->id);
    }

    public function test_residential_candidates_exclude_wrong_specialized_objects(): void
    {
        $candidates = [
            $this->candidate(
                120104501,
                '12-01-045-01',
                'Устройство козырьков на металлических кронштейнах с покрытием кровельной сталью',
                '100 m2',
                '12-01',
            ),
            $this->candidate(
                120102001,
                '12-01-020-01',
                'Устройство кровель из металлочерепицы по готовым прогонам',
                '100 m2',
                '12-01',
            ),
            $this->candidate(
                120101305,
                '12-01-013-05',
                'Устройство теплоизоляции кровли плитами насухо',
                '100 m2',
                '12-01',
            ),
            $this->candidate(
                120101601,
                '12-01-016-01',
                'Устройство выравнивающей цементной стяжки кровли',
                '100 m2',
                '12-01',
            ),
            $this->candidate(
                70700301,
                '07-07-003-01',
                'Устройство перегородок панельных в зданиях промышленных и сельскохозяйственных предприятий',
                '100 m2',
                '07-07',
            ),
        ];

        $selected = (new NormativeIntentCandidateRanker)->select($candidates, [[
            'search_text' => 'Монтаж кровельного покрытия',
            'unit' => 'm2',
            'action' => 'general_work',
            'scope' => 'roof',
            'object_type' => 'residential',
            'normative_sections' => ['12'],
        ]]);

        self::assertNotNull($selected);
        self::assertSame([120102001], array_column($selected, 'id'));
    }

    public function test_electrical_candidates_require_installation_of_requested_object(): void
    {
        $candidates = [
            $this->candidate(
                80215216,
                '08-02-152-16',
                'Блок кабельных конструкций из одинарных стоек из угловой стали, устанавливаемый на стене',
                '100 m',
                '08-02',
            ),
            $this->candidate(
                80240201,
                '08-02-402-01',
                'Кабель трех-пятижильный, прокладываемый по установленным конструкциям и лоткам',
                '100 m',
                '08-02',
            ),
        ];

        $selected = (new NormativeIntentCandidateRanker)->select($candidates, [[
            'search_text' => 'Прокладка силовых кабельных линий',
            'unit' => 'm',
            'action' => 'cable_installation',
            'scope' => 'engineering',
            'system' => 'electrical',
            'object_type' => 'residential',
            'normative_sections' => ['08'],
        ]]);

        self::assertNotNull($selected);
        self::assertSame([80240201], array_column($selected, 'id'));
    }

    private function candidate(
        int $id,
        string $code,
        string $name,
        string $unit = '100 m',
        string $sectionCode = '16-04',
    ): object {
        return (object) [
            'id' => $id,
            'code' => $code,
            'name' => $name,
            'canonical_unit' => $unit,
            'unit' => $unit,
            'section_code' => $sectionCode,
            'section_name' => 'Трубопроводы из пластмассовых труб',
            'work_composition' => [],
        ];
    }
}
