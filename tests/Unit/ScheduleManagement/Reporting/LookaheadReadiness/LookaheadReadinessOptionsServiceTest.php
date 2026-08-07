<?php

declare(strict_types=1);

namespace Tests\Unit\ScheduleManagement\Reporting\LookaheadReadiness;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceReadiness;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSourceReadinessStatus;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\LookaheadReadinessCandidateContract;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Services\LookaheadReadinessOptionsService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LookaheadReadinessOptionsServiceTest extends TestCase
{
    public function test_candidate_definition_matches_existing_runtime_contract(): void
    {
        $definition = (new LookaheadReadinessCandidateContract)->definition();

        self::assertSame(LookaheadReadinessCandidateContract::CODE, $definition->code);
        self::assertSame(LookaheadReadinessCandidateContract::FORMULA_VERSION, $definition->formulaVersion);
        self::assertSame(['schedule.view'], $definition->permissionPolicy->viewPermissions);
        self::assertSame(['schedule.reports.export'], $definition->permissionPolicy->exportPermissions);
    }

    #[DataProvider('availabilityCases')]
    public function test_availability_distinguishes_no_data_incomplete_source_and_available(
        ReportSourceReadiness $readiness,
        string $expectedStatus,
    ): void {
        $availability = LookaheadReadinessOptionsService::availability(
            $readiness,
            new CarbonImmutable('2026-08-07T00:00:00+03:00'),
            14,
        );

        self::assertSame($expectedStatus, $availability['status']);
        self::assertSame('2026-08-07', $availability['period_from']);
        self::assertSame('2026-08-21', $availability['period_to']);
        self::assertSame($expectedStatus === 'available', $availability['can_run']);
    }

    public static function availabilityCases(): array
    {
        return [
            'no eligible facts' => [self::readiness(ReportSourceReadinessStatus::READY, 0, 0, 0), 'no_data'],
            'facts with source gaps' => [self::readiness(ReportSourceReadinessStatus::PARTIAL, 3, 2, 1), 'source_incomplete'],
            'complete facts' => [self::readiness(ReportSourceReadinessStatus::READY, 3, 3, 0), 'available'],
        ];
    }

    private static function readiness(
        ReportSourceReadinessStatus $status,
        int $eligible,
        int $projected,
        int $gaps,
    ): ReportSourceReadiness {
        return new ReportSourceReadiness(
            $status,
            $eligible,
            $projected,
            $gaps,
            0,
            'test:0',
            str_repeat('a', 64),
            str_repeat('b', 64),
            $status === ReportSourceReadinessStatus::READY ? CarbonImmutable::parse('2026-08-07T00:00:00+03:00') : null,
        );
    }
}
