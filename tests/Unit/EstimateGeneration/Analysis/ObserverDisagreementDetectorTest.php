<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Analysis;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunResult;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Routing\ObserverDisagreementDetector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ObserverDisagreementDetectorTest extends TestCase
{
    #[Test]
    public function complementary_structured_observations_do_not_force_paid_arbitration(): void
    {
        $results = [
            'observer_literal' => $this->roleResult('building', 'section_name', 'Архитектурные решения'),
            'observer_construction' => $this->roleResult('wall', 'material', 'Газобетон'),
        ];

        self::assertFalse((new ObserverDisagreementDetector)->hasMaterialDisagreement($results));
    }

    #[Test]
    public function different_values_for_the_same_fact_force_arbitration(): void
    {
        $results = [
            'observer_literal' => $this->roleResult('wall-1', 'thickness', '300 мм'),
            'observer_construction' => $this->roleResult('wall-1', 'thickness', '375 мм'),
        ];

        self::assertTrue((new ObserverDisagreementDetector)->hasMaterialDisagreement($results));
    }

    private function roleResult(string $entity, string $type, string $value): AiRoleRunResult
    {
        return new AiRoleRunResult(['claims' => [[
            'entityKey' => $entity,
            'factType' => $type,
            'value' => ['type' => 'string', 'data' => $value],
        ]]], 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
    }
}
