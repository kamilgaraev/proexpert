<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\BuildingModel;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\GenerationBuildingModelRefreshPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GenerationBuildingModelRefreshPolicyTest extends TestCase
{
    #[DataProvider('modelStates')]
    public function test_only_an_active_user_confirmation_preserves_confirmed_geometry(
        string $scaleStatus,
        bool $hasActiveUserConfirmation,
        bool $expected,
    ): void {
        self::assertSame(
            $expected,
            (new GenerationBuildingModelRefreshPolicy)->preservesLatestModel(
                $scaleStatus,
                $hasActiveUserConfirmation,
            ),
        );
    }

    public static function modelStates(): iterable
    {
        yield 'active confirmation' => ['confirmed', true, true];
        yield 'invalidated confirmation forces rebuild' => ['confirmed', false, false];
        yield 'automatic estimated scale forces rebuild' => ['estimated', true, false];
        yield 'unknown scale forces rebuild' => ['unknown', true, false];
    }
}
