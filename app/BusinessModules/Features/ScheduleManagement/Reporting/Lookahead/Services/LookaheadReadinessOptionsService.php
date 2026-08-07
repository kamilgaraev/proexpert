<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Services;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceReadiness;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\LookaheadReadinessCandidateContract;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Readiness\LookaheadReadinessProbe;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class LookaheadReadinessOptionsService
{
    public function __construct(
        private LookaheadReadinessProbe $readiness,
        private LookaheadReadinessCandidateContract $contract,
    ) {}

    /** @return array<string, mixed> */
    public function options(
        ReportExecutionContext $context,
        ReportScope $scope,
        DateTimeImmutable $asOf,
        int $horizonDays,
    ): array {
        if ($context->scope->canonicalIdentity() !== $scope->canonicalIdentity()) {
            throw new InvalidArgumentException('lookahead_options_scope_mismatch');
        }

        $query = new ReportQuery(
            $this->contract->definition(),
            $scope,
            new ReportFilterSet([
                'as_of' => $asOf->format(DATE_ATOM),
                'horizon_days' => $horizonDays,
            ]),
            [],
            $asOf,
            'ru-RU',
        );
        $availability = self::availability(
            $this->readiness->inspect($context, $query),
            $asOf,
            $horizonDays,
        );

        return [
            'availability' => $availability,
            'period' => [
                'default_as_of' => $asOf->format('Y-m-d'),
                'default_horizon_days' => $horizonDays,
                'min_horizon_days' => 1,
                'max_horizon_days' => 366,
            ],
        ];
    }

    /** @return array{status:string,can_run:bool,period_from:string,period_to:string} */
    public static function availability(
        ReportSourceReadiness $readiness,
        DateTimeImmutable $asOf,
        int $horizonDays,
    ): array {
        if ($horizonDays < 1 || $horizonDays > 366) {
            throw new InvalidArgumentException('lookahead_horizon_filter_invalid');
        }

        $status = match (true) {
            $readiness->eligibleCount === 0 => 'no_data',
            $readiness->isReady() => 'available',
            default => 'source_incomplete',
        };

        return [
            'status' => $status,
            'can_run' => $status === 'available',
            'period_from' => $asOf->format('Y-m-d'),
            'period_to' => $asOf->modify('+'.$horizonDays.' days')->format('Y-m-d'),
        ];
    }
}
