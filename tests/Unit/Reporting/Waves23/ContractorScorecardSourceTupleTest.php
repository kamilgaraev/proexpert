<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\DTO\ContractorScorecardSourceTuple;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\ReportDefinitionBuilder;
use Tests\Support\Reporting\ReportExecutionContextBuilder;

final class ContractorScorecardSourceTupleTest extends TestCase
{
    #[Test]
    public function exact_source_set_is_scope_compatible_and_has_deterministic_hash(): void
    {
        $scope = new ReportScope(41, [41], [7], [], new DateTimeZone('UTC'));
        $context = (new ReportExecutionContextBuilder())
            ->scope($scope)
            ->authorization(new \App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext(
                'http',
                41,
                [41],
                [7],
                [],
                new DateTimeZone('UTC'),
                'scorecard-test',
                null,
            ))
            ->build();
        $query = new ReportQuery(
            (new ReportDefinitionBuilder())->code('contractor_scorecard')->payload(),
            $scope,
            new ReportFilterSet(['project_ids' => [7], 'cohort' => '2026-Q2']),
            [],
            new DateTimeImmutable('2026-07-26T12:00:00Z'),
            'ru',
        );

        $tuple = new ContractorScorecardSourceTuple(
            $this->snapshot('baseline_schedule_variance', $scope, 'a'),
            $this->snapshot('supply_reliability', $scope, 'b'),
            $this->snapshot('quality_defect_flow', $scope, 'c'),
            $this->snapshot('safety_incident_actions', $scope, 'd'),
            $this->snapshot('marketplace_reviews', $scope, 'e'),
        );

        $tuple->assertCompatible($context, $query);

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $tuple->hash());
        self::assertSame($tuple->hash(), $tuple->hash());
    }

    #[Test]
    public function stale_source_ref_blocks_tuple_compatibility(): void
    {
        $scope = new ReportScope(41, [41], [7], [], new DateTimeZone('UTC'));
        $context = (new ReportExecutionContextBuilder())
            ->scope($scope)
            ->authorization(new \App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext(
                'http',
                41,
                [41],
                [7],
                [],
                new DateTimeZone('UTC'),
                'scorecard-test',
                null,
            ))
            ->build();
        $query = new ReportQuery(
            (new ReportDefinitionBuilder())->code('contractor_scorecard')->payload(),
            $scope,
            new ReportFilterSet([]),
            [],
            new DateTimeImmutable('2026-07-26T12:00:00Z'),
            'ru',
        );
        $stale = $this->snapshot('supply_reliability', $scope, 'b', new DateTimeImmutable('2026-07-26T11:59:59Z'));

        $tuple = new ContractorScorecardSourceTuple(
            $this->snapshot('baseline_schedule_variance', $scope, 'a'),
            $stale,
            $this->snapshot('quality_defect_flow', $scope, 'c'),
            $this->snapshot('safety_incident_actions', $scope, 'd'),
            $this->snapshot('marketplace_reviews', $scope, 'e'),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('contractor_scorecard_source_tuple_incompatible');

        $tuple->assertCompatible($context, $query);
    }

    #[Test]
    public function mismatched_cohort_blocks_tuple_compatibility(): void
    {
        $scope = new ReportScope(41, [41], [7], [], new DateTimeZone('UTC'));
        $context = (new ReportExecutionContextBuilder())
            ->scope($scope)
            ->authorization(new \App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext(
                'http',
                41,
                [41],
                [7],
                [],
                new DateTimeZone('UTC'),
                'scorecard-test',
                null,
            ))
            ->build();
        $query = new ReportQuery(
            (new ReportDefinitionBuilder())->code('contractor_scorecard')->payload(),
            $scope,
            new ReportFilterSet(['cohort' => '2026-Q2']),
            [],
            new DateTimeImmutable('2026-07-26T12:00:00Z'),
            'ru',
        );
        $tuple = new ContractorScorecardSourceTuple(
            $this->snapshot('baseline_schedule_variance', $scope, 'a'),
            $this->snapshot('supply_reliability', $scope, 'b', cohortKey: '2026-Q1'),
            $this->snapshot('quality_defect_flow', $scope, 'c'),
            $this->snapshot('safety_incident_actions', $scope, 'd'),
            $this->snapshot('marketplace_reviews', $scope, 'e'),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('contractor_scorecard_source_tuple_incompatible');

        $tuple->assertCompatible($context, $query);
    }

    private function snapshot(
        string $kind,
        ReportScope $scope,
        string $hashCharacter,
        ?DateTimeImmutable $staleAt = null,
        string $cohortKey = '2026-Q2',
    ): ReportSnapshotRef {
        return new ReportSnapshotRef(
            $kind,
            $kind.'-snapshot',
            $scope,
            new Sha256Hash(str_repeat('f', 64)),
            $kind.'.v1',
            new Sha256Hash(str_repeat($hashCharacter, 64)),
            new DateTimeImmutable('2026-07-26T10:00:00Z'),
            $staleAt,
            [
                'source_schema_version' => $kind.'.v1',
                'as_of' => '2026-07-26T12:00:00+00:00',
                'cohort_key' => $cohortKey,
                'project_ids' => [7],
            ],
            ReportSnapshotClassification::OPERATIONAL,
            null,
        );
    }
}
