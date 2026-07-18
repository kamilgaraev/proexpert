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

    private function candidate(int $id, string $code, string $name): object
    {
        return (object) [
            'id' => $id,
            'code' => $code,
            'name' => $name,
            'canonical_unit' => '100 m',
            'unit' => '100 m',
            'section_code' => '16-04',
            'section_name' => 'Трубопроводы из пластмассовых труб',
            'work_composition' => [],
        ];
    }
}
