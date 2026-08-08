<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\AcceptedProductionFilterValidator;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\ReportDefinitionBuilder;

final class AcceptedProductionFilterValidatorTest extends TestCase
{
    public function test_all_published_filters_accept_their_canonical_shapes(): void
    {
        (new AcceptedProductionFilterValidator)->validate($this->query([
            'period_from' => '2026-08-01',
            'period_to' => '2026-08-05',
            'work_ids' => [1, 2],
            'act_ids' => [3, 4],
            'contractor_ids' => [5, 6],
            'unit_codes' => ['m3', 't'],
            'zones' => ['A', 'B'],
            'statuses' => ['accepted', 'reversed'],
        ]));

        self::addToAssertionCount(1);
    }

    public function test_malformed_public_filters_are_rejected_with_standard_errors(): void
    {
        $cases = [
            [['period_from' => '2026-02-30', 'period_to' => '2026-08-05'], ReportErrorCode::REPORT_FILTER_RANGE_INVALID],
            [['period_from' => '2026-08-05', 'period_to' => '2026-08-01'], ReportErrorCode::REPORT_FILTER_RANGE_INVALID],
            [['period_from' => '2026-08-01', 'period_to' => '2026-08-05', 'work_ids' => [1, '2']], ReportErrorCode::REPORT_REQUEST_INVALID],
            [['period_from' => '2026-08-01', 'period_to' => '2026-08-05', 'act_ids' => ['id' => 3]], ReportErrorCode::REPORT_REQUEST_INVALID],
            [['period_from' => '2026-08-01', 'period_to' => '2026-08-05', 'contractor_ids' => '5'], ReportErrorCode::REPORT_REQUEST_INVALID],
            [['period_from' => '2026-08-01', 'period_to' => '2026-08-05', 'unit_codes' => ['m3', 7]], ReportErrorCode::REPORT_REQUEST_INVALID],
            [['period_from' => '2026-08-01', 'period_to' => '2026-08-05', 'zones' => ['A', '']], ReportErrorCode::REPORT_REQUEST_INVALID],
            [['period_from' => '2026-08-01', 'period_to' => '2026-08-05', 'statuses' => ['approved']], ReportErrorCode::REPORT_REQUEST_INVALID],
            [['period_from' => '2026-08-01', 'period_to' => '2026-08-05', 'performance_act_ids' => [3]], ReportErrorCode::REPORT_FILTER_UNSUPPORTED],
        ];

        foreach ($cases as [$filters, $expectedCode]) {
            try {
                (new AcceptedProductionFilterValidator)->validate($this->query($filters));
                self::fail('Malformed filter set was accepted.');
            } catch (ReportContractException $exception) {
                self::assertSame($expectedCode, $exception->errorCode);
                self::assertSame([], $exception->safeFields);
            }
        }
    }

    public function test_server_project_context_must_match_the_authorized_scope(): void
    {
        foreach ([
            ['organization_id' => '39', 'project_id' => '17'],
            ['organization_id' => '38', 'project_id' => '18'],
            ['organization_id' => '38'],
        ] as $context) {
            try {
                (new AcceptedProductionFilterValidator)->validate($this->query([
                    'period_from' => '2026-08-01',
                    'period_to' => '2026-08-05',
                    ...$context,
                ], false));
                self::fail('Foreign or missing server scope was accepted.');
            } catch (ReportContractException $exception) {
                self::assertSame(ReportErrorCode::REPORT_SCOPE_FORBIDDEN, $exception->errorCode);
                self::assertSame([], $exception->safeFields);
            }
        }
    }

    private function query(array $filters, bool $withServerContext = true): ReportQuery
    {
        $scope = new ReportScope(38, [38], [17], [], new DateTimeZone('Europe/Moscow'));
        if ($withServerContext) {
            $filters = [
                ...$filters,
                'organization_id' => '38',
                'project_id' => '17',
            ];
        }

        return new ReportQuery(
            (new ReportDefinitionBuilder)
                ->code('accepted_production_progress')
                ->formulaVersion('accepted_production.v1')
                ->sourceSchemaVersion('production_acceptance_events_v2')
                ->payload(),
            $scope,
            new ReportFilterSet($filters),
            [],
            new DateTimeImmutable('2026-08-05T23:59:59+03:00'),
            'ru-RU',
        );
    }
}
