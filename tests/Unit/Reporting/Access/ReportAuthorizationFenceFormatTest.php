<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Access;

use App\BusinessModules\Core\Reporting\Application\Access\ReportAuthorizationFence;
use App\BusinessModules\Core\Reporting\Application\Access\ReportAuthorizationSubject;
use App\BusinessModules\Core\Reporting\Application\Access\ReportExecutionContextFactory;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportExactManyAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchAggregate;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorization;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorizationTarget;
use App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportVisibility;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\ReportDefinitionBuilder;

final class ReportAuthorizationFenceFormatTest extends TestCase
{
    public function test_export_fence_requires_format(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_authorization_fence_invalid');

        $this->fence($this->runSubject(), [ReportOperation::EXPORT], null);
    }

    public function test_export_format_is_part_of_fence_fingerprint(): void
    {
        $subject = $this->runSubject();

        self::assertNotSame(
            $this->fence($subject, [ReportOperation::EXPORT], 'xlsx')->fingerprint,
            $this->fence($subject, [ReportOperation::EXPORT], 'pdf')->fingerprint,
        );
    }

    public function test_assert_current_authorizes_target_with_exact_export_format(): void
    {
        $context = $this->context();
        $authorizer = new RecordingReportExactManyAuthorizer($context);
        $fence = $this->fence($this->exportSubject('xlsx'), [ReportOperation::EXPORT], 'xlsx', $authorizer);

        $fence->assertCurrent($context);

        self::assertCount(1, $authorizer->targets);
        self::assertSame(ReportOperation::EXPORT, $authorizer->targets[0]->operation);
        self::assertSame('xlsx', $authorizer->targets[0]->exportFormat);
    }

    public function test_persisted_pdf_export_cannot_be_reauthorized_as_xlsx(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_authorization_fence_invalid');

        $this->fence($this->exportSubject('pdf'), [ReportOperation::EXPORT], 'xlsx');
    }

    /** @param list<ReportOperation> $operations */
    private function fence(
        ReportAuthorizationSubject $subject,
        array $operations,
        ?string $format,
        ?CurrentReportExactManyAuthorizer $authorizer = null,
    ): ReportAuthorizationFence {
        return new ReportAuthorizationFence(
            $subject,
            $operations,
            $format,
            $authorizer ?? new RecordingReportExactManyAuthorizer($this->context()),
            new ReportExecutionContextFactory,
        );
    }

    private function runSubject(): ReportAuthorizationSubject
    {
        $definition = $this->definition();
        $scope = $this->scope();

        return new ReportAuthorizationSubject(
            ReportDispatchAggregate::RUN,
            '01J4XKQ8VQ5Z6K0N8X8YF8V1A2',
            $definition,
            $scope,
            $this->snapshot($definition, $scope),
            null,
            null,
        );
    }

    private function exportSubject(string $format): ReportAuthorizationSubject
    {
        $definition = $this->definition();
        $scope = $this->scope();

        return new ReportAuthorizationSubject(
            ReportDispatchAggregate::EXPORT,
            '01J4XKQ8VQ5Z6K0N8X8YF8V1A2',
            $definition,
            $scope,
            $this->snapshot($definition, $scope),
            '01J4XKQ8VQ5Z6K0N8X8YF8V1A3',
            new Sha256Hash(str_repeat('c', 64)),
            new Sha256Hash(str_repeat('d', 64)),
            $format,
        );
    }

    private function context(): ReportExecutionContext
    {
        $scope = $this->scope();
        $actor = new ReportActor(17, 'active', ['reports.export', 'reports.view']);
        $decision = new AuthorizationDecisionContext(
            'queue',
            $scope->organizationId,
            $scope->holdingOrganizationIds,
            $scope->projectIds,
            $scope->resources,
            $scope->timezone,
            'report-authorization-fence-format-test',
            null,
        );

        return new ReportExecutionContext(
            $actor,
            $scope,
            new ReportVisibility(true, true, true, true, false, false, false),
            $decision,
        );
    }

    private function definition(): ReportDefinition
    {
        return (new ReportDefinitionBuilder)->formats(['xlsx', 'pdf'])->payload();
    }

    private function scope(): ReportScope
    {
        return new ReportScope(41, [41], [], [], new DateTimeZone('UTC'));
    }

    private function snapshot(ReportDefinition $definition, ReportScope $scope): ReportSnapshotRef
    {
        return new ReportSnapshotRef(
            'report',
            'snapshot',
            $scope,
            $definition->definitionHash,
            $definition->formulaVersion,
            new Sha256Hash(str_repeat('b', 64)),
            new DateTimeImmutable('2026-08-01T12:00:00.000000Z'),
            null,
            [],
            ReportSnapshotClassification::OPERATIONAL,
            null,
        );
    }
}

final class RecordingReportExactManyAuthorizer implements CurrentReportExactManyAuthorizer
{
    /** @var list<CurrentReportAuthorizationTarget> */
    public array $targets = [];

    public function __construct(private readonly ReportExecutionContext $context) {}

    public function authorizeExactMany(int $actorId, ReportScope $requestedScope, array $targets): array
    {
        $this->targets = $targets;

        return array_map(
            fn (CurrentReportAuthorizationTarget $target): CurrentReportAuthorization => new CurrentReportAuthorization(
                $this->context->actor,
                $this->context->authorization,
                $this->context->visibility,
                $target,
            ),
            $targets,
        );
    }
}
