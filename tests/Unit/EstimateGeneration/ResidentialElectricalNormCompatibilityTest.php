<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeSemanticCompatibilityService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ResidentialElectricalNormCompatibilityTest extends TestCase
{
    #[Test]
    public function residential_cable_work_rejects_mining_cable_norm(): void
    {
        $service = new NormativeSemanticCompatibilityService;
        $intent = [
            'action' => 'cable_installation',
            'scope' => 'engineering',
            'system' => 'electrical',
            'object_type' => 'residential',
        ];

        self::assertFalse($service->isCompatible(
            'Кабельные линии до 110 кВ по вертикальным горным выработкам с бетонной и металлической крепью по установленным конструкциям с прокладкой с помощью шахтной клети',
            'Прокладка силовых линий',
            $intent,
        ));
        self::assertTrue($service->isCompatible(
            'Кабель трех-пятижильный по установленным конструкциям и лоткам с установкой ответвительных коробок в помещениях с нормальной средой',
            'Прокладка силовых линий',
            $intent,
        ));
    }
}
