<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\DescriptionNumericProposals;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use PHPUnit\Framework\TestCase;

final class DescriptionNumericProposalsTest extends TestCase
{
    public function test_only_explicit_parameter_with_compatible_unit_is_proposed_without_an_entity(): void
    {
        $rows = DescriptionNumericProposals::parse('Площадь: 12,50 м². Высота 300 см; толщина 10 мм. 8×9 метров; цена 123 ₽; 3 этажа.', 'fact:description', 1, str_repeat('a', 64));
        self::assertSame(['area', 'height', 'thickness'], array_column($rows, 'parameter'));
        self::assertSame(['12.5', '300', '10'], array_column($rows, 'value'));
        self::assertSame(['m2', 'cm', 'mm'], array_column($rows, 'unit'));
        self::assertSame('Площадь: 12,50 м²', $rows[0]['quote']);
        self::assertSame('needs_confirmation', $rows[0]['status']);
        self::assertArrayNotHasKey('entity_id', $rows[0]);
        self::assertSame([], DescriptionNumericProposals::parse('Площадь 12 м. Высота 3 м2. Длина 5 м3. Ширина -2 м. Глубина 0 м. 25 м².', 'fact:description', 1, str_repeat('a', 64)));
    }

    public function test_identity_pins_source_version_value_hash_and_exact_mention_and_limits_work(): void
    {
        $first = DescriptionNumericProposals::parse('длина 2 м; длина 2 м', 'fact:description', 1, str_repeat('a', 64));
        self::assertNotSame($first[0]['proposal_id'], $first[1]['proposal_id']);
        $changed = DescriptionNumericProposals::parse('длина 2 м', 'fact:description', 2, str_repeat('a', 64));
        self::assertNotSame($first[0]['proposal_id'], $changed[0]['proposal_id']);
        $changed = DescriptionNumericProposals::parse('длина 2 м', 'fact:description', 1, str_repeat('b', 64));
        self::assertNotSame($first[0]['proposal_id'], $changed[0]['proposal_id']);
        self::assertCount(30, DescriptionNumericProposals::parse(str_repeat('длина 2 м; ', 40), 'fact:description', 1, str_repeat('a', 64)));
        self::assertSame([], DescriptionNumericProposals::parse(str_repeat('длина 2 м; ', 2000), 'fact:description', 1, str_repeat('a', 64)));
        self::assertSame([], DescriptionNumericProposals::parse('Площадь'.str_repeat(' ', 900).'12 м²', 'fact:description', 1, str_repeat('a', 64)));
    }

    public function test_source_requires_current_description_user_decision_and_exact_trusted_hash(): void
    {
        $version = 'sha256:'.str_repeat('d', 64);
        $value = ['kind' => 'initial_description', 'description' => 'Площадь 12 м²', 'numeric_parameters_confirmed' => false];
        $fact = new Fact('fact:description', 1, 2, 3, $version, 'entity:description', 'evaluation_answer', $value, null, 0, 'user_input', 'confirmed', ['evidence:description']);
        $decision = new Decision('decision:description', 1, 2, 3, $version, 'fact', $fact->id, $fact->id, 'user', '1', 'Описание введено пользователем', 1, $fact->evidenceIds);
        $hash = hash('sha256', CanonicalPipelineJson::encode($value));
        $proof = new Evidence('evidence:description', 1, 2, 3, $version, 'input:3', 'user_input', null, null, 'input_payload.description', 'user_input_normalizer', 'input:3', $hash);
        $snapshot = new ProjectModelSnapshot([], [$fact], [$proof], []);
        self::assertCount(1, DescriptionNumericProposals::fromSnapshot($snapshot, $value['description'], [$decision]));
        self::assertSame([], DescriptionNumericProposals::fromSnapshot($snapshot, $value['description'], []));
        self::assertSame([], DescriptionNumericProposals::fromSnapshot($snapshot, 'Площадь 15 м²', [$decision]));
        foreach ([['user_input_normalizer', str_repeat('a', 64)], ['vision_observer', $hash]] as [$producer, $badHash]) {
            $badProof = new Evidence('evidence:description', 1, 2, 3, $version, 'input:3', 'user_input', null, null, 'input_payload.description', $producer, 'input:3', $badHash);
            self::assertSame([], DescriptionNumericProposals::fromSnapshot(new ProjectModelSnapshot([], [$fact], [$badProof], []), $value['description'], [$decision]));
        }
    }
}
