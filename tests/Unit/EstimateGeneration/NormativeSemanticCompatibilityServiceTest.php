<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeSemanticCompatibilityService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NormativeSemanticCompatibilityServiceTest extends TestCase
{
    #[DataProvider('incompatibleResidentialNorms')]
    public function test_rejects_norms_that_do_not_match_residential_work_semantics(
        string $workText,
        string $candidateText,
        string $action,
    ): void {
        $service = new NormativeSemanticCompatibilityService;

        $this->assertFalse($service->isCompatible(
            $candidateText,
            $workText,
            ['scope' => 'foundation', 'action' => $action],
        ));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function incompatibleResidentialNorms(): array
    {
        return [
            'temporary fence is not grounding conductor' => [
                'Временное ограждение площадки',
                'Проводник заземляющий открыто по строительным основаниям из полосовой стали',
                'fence_installation',
            ],
            'foundation concrete is not reactor construction' => [
                'Бетонирование фундаментов',
                'Бетонирование конструкций шахты реактора: электропрогрев серпентинитового бетона сухой защиты реактора',
                'concreting',
            ],
            'foundation reinforcement is not reactor construction' => [
                'Армирование фундаментов',
                'Установка арматуры реакторного отделения краном СКР',
                'reinforcement',
            ],
            'backfill is not excavation' => [
                'Обратная засыпка пазух',
                'Разработка грунта в траншеях экскаватором обратная лопата',
                'backfill',
            ],
            'residential excavation is not hydroenergy quarry work' => [
                'Вывоз излишнего грунта',
                'Разработка грунта с погрузкой карьерными экскаваторами в гидроэнергетическом строительстве',
                'excavation',
            ],
            'partitions are not wall drainage' => [
                'Внутренние перегородки',
                'Устройство систем дренажа внутренних поверхностей стен',
                'general_work',
            ],
            'wall masonry is not clay insulation' => [
                'Кладка наружных стен',
                'Боковая изоляция стен фундаментов глиной',
                'masonry',
            ],
        ];
    }

    #[DataProvider('compatibleResidentialNorms')]
    public function test_accepts_norms_with_matching_work_semantics(
        string $workText,
        string $candidateText,
        string $action,
    ): void {
        $service = new NormativeSemanticCompatibilityService;

        $this->assertTrue($service->isCompatible(
            $candidateText,
            $workText,
            ['scope' => 'foundation', 'action' => $action],
        ));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function compatibleResidentialNorms(): array
    {
        return [
            'strip foundation formwork' => [
                'Опалубка фундаментов',
                'Монтаж мелкощитовой опалубки фундаментов ленточных',
                'formwork',
            ],
            'foundation waterproofing' => [
                'Гидроизоляция фундаментов',
                'Гидроизоляция боковая обмазочная битумная стен фундаментов',
                'waterproofing',
            ],
            'manual excavation' => [
                'Разработка грунта под фундаменты',
                'Разработка грунта вручную в котлованах глубиной до 2 м',
                'excavation',
            ],
            'block partitions' => [
                'Внутренние перегородки',
                'Кладка перегородок из газобетонных блоков',
                'general_work',
            ],
        ];
    }
}
