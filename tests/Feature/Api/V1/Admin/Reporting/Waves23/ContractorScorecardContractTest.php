<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin\Reporting\Waves23;

use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\DTO\ContractorObjectiveObservationIndex;
use App\BusinessModules\Core\Reporting\Application\Access\ReportEvidenceRedactor;
use App\BusinessModules\Core\Reporting\Application\Access\ReportSourceObjectAuthorizer;
use App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportVisibility;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ContractorScorecardContractTest extends TestCase
{
    #[Test]
    public function objective_observations_are_selected_only_from_the_requested_cohort(): void
    {
        $index = new ContractorObjectiveObservationIndex([
            'quality_defect_flow' => [
                31 => [
                    41 => [
                        [
                            'id' => 1,
                            'row_key' => 'old',
                            'snapshot_id' => 'old-snapshot',
                            'cycle_days' => '2',
                            'closed_flag' => true,
                            '_observed_at' => '2026-03-31T23:59:59Z',
                        ],
                        [
                            'id' => 2,
                            'row_key' => 'current',
                            'snapshot_id' => 'current-snapshot',
                            'cycle_days' => '7',
                            'closed_flag' => true,
                            '_observed_at' => '2026-04-01T00:00:00Z',
                        ],
                    ],
                ],
            ],
        ]);

        $observations = $index->observations(
            'quality_defect_flow',
            31,
            41,
            'cycle_days',
            'days',
            '2026-Q2',
            'quarter',
        );

        self::assertCount(1, $observations['signals']);
        self::assertSame('7', $observations['signals'][0]->value);
        self::assertSame(2, $observations['evidence'][0]['source_row_id']);
    }

    #[Test]
    public function source_authorization_fails_closed_before_object_lookup_for_foreign_scope(): void
    {
        $context = new ReportExecutionContext(
            new ReportActor(17, 'active', ['contractor_marketplace.profile.view']),
            new ReportScope(10, [10], [101], [], new DateTimeZone('Europe/Moscow')),
            new ReportVisibility(true, true, false, false, false, false, false),
            new AuthorizationDecisionContext(
                'http',
                10,
                [10],
                [101],
                [],
                new DateTimeZone('Europe/Moscow'),
                'contractor-abac-regression',
                null,
            ),
        );

        self::assertSame(
            'forbidden',
            (new ReflectionClass(ReportSourceObjectAuthorizer::class))
                ->newInstanceWithoutConstructor()
                ->availability(
                    $context,
                    'quality_defect_flow',
                    7331,
                    11,
                    101,
                ),
        );
    }

    #[Test]
    public function forbidden_source_evidence_is_redacted_without_leaking_its_identity(): void
    {
        $payload = (new ReportEvidenceRedactor)->reference(
            [
                'source_report_code' => 'quality_defect_flow',
                'source_row_id' => 7331,
                'source_row_key' => 'secret-row',
                'source_snapshot_id' => '01SECRET',
            ],
            'quality_defect_flow',
            7331,
            'forbidden',
        );

        self::assertSame('redacted', $payload['availability']);
        self::assertSame('quality_defect_flow', $payload['source_type']);
        self::assertStringStartsWith('redacted:', $payload['row_key']);
        self::assertArrayNotHasKey('source_row_id', $payload);
        self::assertArrayNotHasKey('source_row_key', $payload);
        self::assertArrayNotHasKey('source_snapshot_id', $payload);
    }
}
