<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeSemanticCompatibilityService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NormativeSemanticCompatibilityServiceTest extends TestCase
{
    public function test_exposes_the_same_action_vocabulary_for_retrieval_and_validation(): void
    {
        $service = new NormativeSemanticCompatibilityService;

        self::assertNotEmpty($service->markersForAction('insulation'));
        self::assertNotEmpty($service->markersForAction('concreting'));
        self::assertNotEmpty($service->markersForAction('fence_installation'));
        self::assertSame([], $service->markersForAction('unknown_action'));
    }

    public function test_reinforced_concrete_context_does_not_turn_formwork_into_concreting(): void
    {
        $service = new NormativeSemanticCompatibilityService;

        self::assertFalse($service->isCompatible(
            'Монтаж мелкощитовой опалубки монолитных железобетонных конструкций фундаментов',
            'Бетонирование железобетонных фундаментов',
            ['action' => 'concreting'],
        ));
        self::assertTrue($service->isCompatible(
            'Устройство монолитных фундаментов. Укладка бетонной смеси в опалубку',
            'Бетонирование железобетонных фундаментов',
            ['action' => 'concreting'],
        ));
    }

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

    public function test_known_action_is_checked_even_when_work_title_uses_english_words(): void
    {
        $service = new NormativeSemanticCompatibilityService;

        self::assertFalse($service->isCompatible(
            'Прокладка заземляющего проводника',
            'Temporary site fence installation',
            ['action' => 'fence_installation'],
        ));
        self::assertTrue($service->isCompatible(
            'Устройство временного ограждения строительной площадки',
            'Temporary site fence installation',
            ['action' => 'fence_installation'],
        ));
    }
}
