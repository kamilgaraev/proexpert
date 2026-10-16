<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeSemanticCompatibilityService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NormativeSemanticFloorWordTest extends TestCase
{
    #[DataProvider('userConfirmationPhrases')]
    public function test_user_confirmation_does_not_turn_a_lighting_request_into_floor_work(string $phrase): void
    {
        self::assertTrue((new NormativeSemanticCompatibilityService)->isCompatible(
            'Монтаж потолочных светильников',
            'Монтаж потолочных светильников. Количество подтверждено '.$phrase,
            ['scope' => 'engineering', 'action' => 'lighting_fixture_installation', 'system' => 'electrical'],
        ));
    }

    public static function userConfirmationPhrases(): array
    {
        return [['пользователем'], ['данными пользователя'], ['данными пользователей'], ['пользовательским актом']];
    }

    #[DataProvider('floorRequests')]
    public function test_a_real_floor_request_still_rejects_a_lighting_norm(string $request): void
    {
        self::assertFalse((new NormativeSemanticCompatibilityService)->isCompatible(
            'Монтаж потолочных светильников', $request, ['scope' => 'finishing'],
        ));
        self::assertTrue((new NormativeSemanticCompatibilityService)->isCompatible($request, $request, ['scope' => 'finishing']));
    }

    public static function floorRequests(): array
    {
        return [['Устройство пола'], ['Устройство полов'], ['Монтаж напольного покрытия']];
    }
}
