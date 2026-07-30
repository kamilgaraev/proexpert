<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin\Reporting\Waves23;

use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\DTO\ContractorObjectiveObservationIndex;
use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\Services\ContractorObjectiveObservationPeriodResolver;
use App\BusinessModules\Core\Reporting\Application\Access\ReportEvidenceRedactor;
use App\BusinessModules\Core\Reporting\Application\Access\ReportSourceObjectAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Access\ReportSourceObjectReader;
use App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportVisibility;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Models\User;
use App\Services\Project\UserProjectAccessService;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ContractorScorecardContractTest extends TestCase
{
    protected function setUpTraits(): array
    {
        return [];
    }

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
        self::assertSame(
            [
                31 => [
                    41 => [
                        '2026-Q1' => true,
                        '2026-Q2' => true,
                    ],
                ],
            ],
            $index->profileProjectCohorts('quarter'),
        );
    }

    #[Test]
    public function objective_source_period_is_taken_only_from_its_canonical_row_field(): void
    {
        $periods = new ContractorObjectiveObservationPeriodResolver;

        self::assertSame(
            '2026-04-01T00:00:00.000000Z',
            $periods->resolve(
                [
                    'cohort_date' => '2026-04-01',
                    'as_of' => '2030-01-01',
                    'cohort_key' => '2030-Q1',
                ],
                'quality_defect_flow',
            ),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('contractor_objective_observation_period_missing');
        $periods->resolve(
            [
                'as_of' => '2026-04-01',
                'cohort_key' => '2026-Q2',
            ],
            'quality_defect_flow',
        );
    }

    #[Test]
    public function scoped_source_authorization_uses_bound_access_services_and_fails_closed(): void
    {
        $actor = (new User)->forceFill(['id' => 17]);
        $actor->exists = true;
        $project = (new Project)->forceFill(['id' => 101, 'organization_id' => 10]);
        $project->exists = true;
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->expects(self::once())
            ->method('can')
            ->with(
                $actor,
                'contractor_marketplace.profile.view',
                [
                    'organization_id' => 10,
                    'project_id' => 101,
                    'source_type' => 'quality_defect_flow',
                    'source_id' => 7331,
                ],
            )
            ->willReturn(false);
        $projectAccess = $this->createMock(UserProjectAccessService::class);
        $projectAccess->expects(self::once())
            ->method('canAccessProject')
            ->with($actor, $project, 10)
            ->willReturn(true);
        $sources = $this->createMock(ReportSourceObjectReader::class);
        $sources->expects(self::once())->method('actor')->with(17)->willReturn($actor);
        $sources->expects(self::once())->method('project')->with(101)->willReturn($project);
        $sources->expects(self::never())->method('exists');
        $this->app->instance(AuthorizationService::class, $authorization);
        $this->app->instance(UserProjectAccessService::class, $projectAccess);
        $this->app->instance(ReportSourceObjectReader::class, $sources);
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
            $this->app->make(ReportSourceObjectAuthorizer::class)->availability(
                $context,
                'quality_defect_flow',
                7331,
                10,
                101,
            ),
        );
    }

    #[Test]
    public function authorized_scoped_source_is_available_only_after_the_scoped_row_exists(): void
    {
        $actor = (new User)->forceFill(['id' => 17]);
        $actor->exists = true;
        $project = (new Project)->forceFill(['id' => 101, 'organization_id' => 10]);
        $project->exists = true;
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->method('can')->willReturn(true);
        $projectAccess = $this->createMock(UserProjectAccessService::class);
        $projectAccess->method('canAccessProject')->willReturn(true);
        $sources = $this->createMock(ReportSourceObjectReader::class);
        $sources->method('actor')->willReturn($actor);
        $sources->method('project')->willReturn($project);
        $sources->expects(self::once())
            ->method('exists')
            ->with('quality_defect_flow', 7331, 10, 101)
            ->willReturn(true);
        $this->app->instance(AuthorizationService::class, $authorization);
        $this->app->instance(UserProjectAccessService::class, $projectAccess);
        $this->app->instance(ReportSourceObjectReader::class, $sources);

        self::assertSame(
            'available',
            $this->app->make(ReportSourceObjectAuthorizer::class)->availability(
                $this->context(),
                'quality_defect_flow',
                7331,
                10,
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

    private function context(): ReportExecutionContext
    {
        return new ReportExecutionContext(
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
    }
}
