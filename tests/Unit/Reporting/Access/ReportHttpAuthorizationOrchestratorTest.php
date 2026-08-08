<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Access;

use App\BusinessModules\Core\Reporting\Application\Access\ReportAuthorizationSubject;
use App\BusinessModules\Core\Reporting\Application\Access\ReportCatalogAuthorization;
use App\BusinessModules\Core\Reporting\Application\Access\ReportExecutionContextFactory;
use App\BusinessModules\Core\Reporting\Application\Access\ReportHttpAuthorizationOrchestrator;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportAuthorizationSubjectReader;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportHttpAuthorizationTargetResolver;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportScopeAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchAggregate;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
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
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\ReportDefinitionBuilder;

final class ReportHttpAuthorizationOrchestratorTest extends TestCase
{
    private const RUN_ID = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    private const EXPORT_ID = '01ARZ3NDEKTSV4RRFFQ69G5FAW';

    #[DataProvider('persistedOperationProvider')]
    public function test_persisted_operations_resolve_server_target_and_authorize_exact_scope(
        string $method,
        ReportDispatchAggregate $aggregate,
        ReportOperation $operation,
    ): void {
        $scope = $this->scope();
        $definition = $this->definition();
        $snapshot = $this->snapshot($scope, $definition);
        $exportFormat = $operation === ReportOperation::EXPORT || $aggregate === ReportDispatchAggregate::EXPORT
            ? $definition->formats[0]
            : null;
        $subject = $aggregate === ReportDispatchAggregate::RUN
            ? $this->runSubject($scope, $definition, $snapshot)
            : $this->exportSubject($scope, $definition, $snapshot);
        $subjects = new RecordingAuthorizationSubjectReader($subject, $subject);
        $resolver = new RecordingAuthorizationTargetResolver(
            new CurrentReportAuthorizationTarget(
                $definition,
                $operation,
                $operation === ReportOperation::RUN ? null : $this->snapshot($scope, $definition),
                $exportFormat,
            ),
        );
        $authorizer = new RecordingCurrentReportScopeAuthorizer;
        $orchestrator = $this->orchestrator($resolver, $subjects, $authorizer);
        $identifier = $aggregate === ReportDispatchAggregate::RUN ? self::RUN_ID : self::EXPORT_ID;

        $result = $method === 'createExport'
            ? $orchestrator->createExport($this->request(), $identifier, (string) $exportFormat)
            : $orchestrator->{$method}($this->request(), $identifier);

        self::assertSame(['context', 'authorization'], array_keys($result));
        self::assertInstanceOf(ReportExecutionContext::class, $result['context']);
        self::assertInstanceOf(CurrentReportAuthorization::class, $result['authorization']);
        self::assertSame($scope->canonicalIdentity(), $result['context']->scope->canonicalIdentity());
        self::assertSame($operation, $result['authorization']->target->operation);
        self::assertSame($exportFormat, $result['authorization']->target->exportFormat);
        self::assertSame($definition->definitionHash->value, $result['context']->grant?->definitionHash);
        self::assertSame($operation, $result['context']->grant?->operation);
        self::assertSame($exportFormat, $result['context']->grant?->exportFormat);
        self::assertCount(1, $authorizer->exactCalls);
        self::assertSame($scope->canonicalIdentity(), $authorizer->exactCalls[0]['scope']->canonicalIdentity());
        self::assertSame($result['authorization']->target, $authorizer->exactCalls[0]['target']);
        self::assertSame([], $authorizer->organizationCalls);
        self::assertSame(
            [$identifier],
            $aggregate === ReportDispatchAggregate::RUN ? $subjects->runIds : $subjects->exportIds,
        );
    }

    public function test_create_run_uses_only_authenticated_actor_anchor_organization_and_server_target(): void
    {
        $definition = $this->definition();
        $resolver = new RecordingAuthorizationTargetResolver(
            new CurrentReportAuthorizationTarget($definition, ReportOperation::RUN, null),
        );
        $authorizer = new RecordingCurrentReportScopeAuthorizer;
        $orchestrator = $this->orchestrator(
            $resolver,
            new RecordingAuthorizationSubjectReader,
            $authorizer,
        );
        $request = $this->request([
            'operation' => 'download',
            'definition_hash' => str_repeat('f', 64),
            'resources' => [['kind' => 'project', 'id' => 999]],
        ]);
        $request->attributes->set('organization_timezone', 'Europe/Moscow');
        $request->attributes->set('allowed_project_ids', [999]);

        $result = $orchestrator->createRun($request, 'report');

        self::assertSame(['context', 'authorization'], array_keys($result));
        self::assertSame($definition->definitionHash->value, $result['context']->grant?->definitionHash);
        self::assertSame(ReportOperation::RUN, $result['context']->grant?->operation);
        self::assertNull($result['context']->grant?->exportFormat);
        self::assertSame(['report'], $resolver->createRunCodes);
        self::assertCount(1, $authorizer->organizationCalls);
        self::assertSame(17, $authorizer->organizationCalls[0]['actorId']);
        self::assertSame(41, $authorizer->organizationCalls[0]['organizationId']);
        self::assertSame('UTC', $authorizer->organizationCalls[0]['timezone']->getName());
        self::assertSame(ReportOperation::RUN, $authorizer->organizationCalls[0]['target']->operation);
        self::assertSame([], $authorizer->exactCalls);
        self::assertSame([], $result['context']->scope->projectIds);
        self::assertSame([], $result['context']->scope->resources);
    }

    public function test_create_run_uses_the_trusted_project_scope_instead_of_the_whole_organization(): void
    {
        $definition = $this->definition();
        $resolver = new RecordingAuthorizationTargetResolver(
            new CurrentReportAuthorizationTarget($definition, ReportOperation::RUN, null),
        );
        $authorizer = new RecordingCurrentReportScopeAuthorizer;
        $orchestrator = $this->orchestrator(
            $resolver,
            new RecordingAuthorizationSubjectReader,
            $authorizer,
        );
        $request = $this->request();
        $request->attributes->set('report_project_scope_id', 73);

        $result = $orchestrator->createRun($request, 'report');

        self::assertSame([], $authorizer->organizationCalls);
        self::assertCount(1, $authorizer->exactCalls);
        self::assertSame(17, $authorizer->exactCalls[0]['actorId']);
        self::assertSame(41, $authorizer->exactCalls[0]['scope']->organizationId);
        self::assertSame([41], $authorizer->exactCalls[0]['scope']->holdingOrganizationIds);
        self::assertSame([73], $authorizer->exactCalls[0]['scope']->projectIds);
        self::assertSame([73], $result['context']->scope->projectIds);
    }

    public function test_persisted_subject_from_another_middleware_organization_fails_before_authorization(): void
    {
        $scope = new ReportScope(42, [42], [], [], new DateTimeZone('UTC'));
        $definition = $this->definition();
        $snapshot = $this->snapshot($scope, $definition);
        $target = new CurrentReportAuthorizationTarget($definition, ReportOperation::VIEW, $snapshot);
        $authorizer = new RecordingCurrentReportScopeAuthorizer;
        $orchestrator = $this->orchestrator(
            new RecordingAuthorizationTargetResolver($target),
            new RecordingAuthorizationSubjectReader(
                $this->runSubject($scope, $definition, $snapshot),
            ),
            $authorizer,
        );

        try {
            $orchestrator->showRun($this->request(), self::RUN_ID);
            self::fail('Cross-organization persisted subject must fail closed.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_SCOPE_FORBIDDEN, $exception->errorCode);
        }

        self::assertSame([], $authorizer->exactCalls);
    }

    public function test_catalog_authorizes_each_definition_independently_and_orders_by_code_then_hash(): void
    {
        $alpha = $this->definition('alpha_report', 'a');
        $zeta = $this->definition('zeta_report', 'f');
        $resolver = new RecordingAuthorizationTargetResolver(
            new CurrentReportAuthorizationTarget($alpha, ReportOperation::VIEW, null),
            [
                new CurrentReportAuthorizationTarget($zeta, ReportOperation::VIEW, null),
                new CurrentReportAuthorizationTarget($alpha, ReportOperation::VIEW, null),
            ],
        );
        $authorizer = new RecordingCurrentReportScopeAuthorizer;
        $authorizer->visibilityByDefinitionHash[$alpha->definitionHash->value] =
            new ReportVisibility(true, false, false, false, false, false, false);
        $authorizer->visibilityByDefinitionHash[$zeta->definitionHash->value] =
            new ReportVisibility(true, true, true, true, true, true, true);
        $orchestrator = $this->orchestrator(
            $resolver,
            new RecordingAuthorizationSubjectReader,
            $authorizer,
        );

        $authorization = $orchestrator->catalog($this->request());

        self::assertInstanceOf(ReportCatalogAuthorization::class, $authorization);
        self::assertSame(
            [$alpha->definitionHash->value, $zeta->definitionHash->value],
            array_keys($authorization->authorizations),
        );
        self::assertCount(1, $authorizer->catalogCalls);
        self::assertSame([], $authorizer->organizationCalls);
        self::assertNotSame(
            $authorization->authorizations[$alpha->definitionHash->value],
            $authorization->authorizations[$zeta->definitionHash->value],
        );
        self::assertNotEquals(
            $authorization->authorizations[$alpha->definitionHash->value]->visibility,
            $authorization->authorizations[$zeta->definitionHash->value]->visibility,
        );
        self::assertSame(
            $authorization->context->authorization->toAuthorizationArray(),
            $authorization->authorizations[$alpha->definitionHash->value]->decision->toAuthorizationArray(),
        );
    }

    public function test_catalog_skips_only_scope_forbidden_definition_and_keeps_available_definitions(): void
    {
        $forbidden = $this->definition('forbidden_report', 'f');
        $available = $this->definition('available_report', 'a');
        $resolver = new RecordingAuthorizationTargetResolver(
            new CurrentReportAuthorizationTarget($available, ReportOperation::VIEW, null),
            [
                new CurrentReportAuthorizationTarget($forbidden, ReportOperation::VIEW, null),
                new CurrentReportAuthorizationTarget($available, ReportOperation::VIEW, null),
            ],
        );
        $authorizer = new RecordingCurrentReportScopeAuthorizer;
        $authorizer->failuresByDefinitionHash[$forbidden->definitionHash->value] =
            ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN);

        $authorization = $this->orchestrator(
            $resolver,
            new RecordingAuthorizationSubjectReader,
            $authorizer,
        )->catalog($this->request());

        self::assertSame(
            [$available->definitionHash->value],
            array_keys($authorization->authorizations),
        );
        self::assertCount(1, $authorizer->catalogCalls);
        self::assertSame([], $authorizer->organizationCalls);
    }

    public function test_catalog_ignores_legacy_module_filter_and_authorizes_every_definition(): void
    {
        $generic = $this->definition('generic_report', 'a');
        $source = $this->definition('source_report', 'b');
        $resolver = new RecordingAuthorizationTargetResolver(
            new CurrentReportAuthorizationTarget($generic, ReportOperation::VIEW, null),
            [
                new CurrentReportAuthorizationTarget($generic, ReportOperation::VIEW, null),
                new CurrentReportAuthorizationTarget($source, ReportOperation::VIEW, null),
            ],
        );
        $request = $this->request();
        $request->attributes->set(
            \App\BusinessModules\Core\Reporting\Http\Admin\Middleware\AuthorizeReportDefinitionAccess::ACCESSIBLE_DEFINITION_HASHES_ATTRIBUTE,
            [$source->definitionHash->value],
        );
        $authorizer = new RecordingCurrentReportScopeAuthorizer;

        $authorization = $this->orchestrator(
            $resolver,
            new RecordingAuthorizationSubjectReader,
            $authorizer,
        )->catalog($request);

        self::assertSame(
            [$generic->definitionHash->value, $source->definitionHash->value],
            array_keys($authorization->authorizations),
        );
        self::assertSame(
            [$generic->definitionHash->value, $source->definitionHash->value],
            array_map(
                static fn (CurrentReportAuthorizationTarget $target): string => $target->definition->definitionHash->value,
                $authorizer->catalogCalls[0]['targets'],
            ),
        );
    }

    public function test_catalog_does_not_swallow_non_scope_authorization_failure(): void
    {
        $definition = $this->definition();
        $target = new CurrentReportAuthorizationTarget($definition, ReportOperation::VIEW, null);
        $authorizer = new RecordingCurrentReportScopeAuthorizer;
        $authorizer->failuresByDefinitionHash[$definition->definitionHash->value] =
            ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);

        $this->expectException(ReportContractException::class);
        $this->expectExceptionMessage(ReportErrorCode::REPORT_INTERNAL_ERROR->value);

        $this->orchestrator(
            new RecordingAuthorizationTargetResolver($target, [$target]),
            new RecordingAuthorizationSubjectReader,
            $authorizer,
        )->catalog($this->request());
    }

    public function test_transaction_wraps_subject_resolution_target_resolution_and_authorization(): void
    {
        $events = new AuthorizationEventRecorder;
        $scope = $this->scope();
        $definition = $this->definition();
        $snapshot = $this->snapshot($scope, $definition);
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())
            ->method('transaction')
            ->willReturnCallback(static function (\Closure $callback) use ($events): mixed {
                $events->items[] = 'transaction:start';
                $result = $callback();
                $events->items[] = 'transaction:end';

                return $result;
            });
        $connection->expects(self::once())
            ->method('statement')
            ->with('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY')
            ->willReturnCallback(static function () use ($events): bool {
                $events->items[] = 'transaction:configured';

                return true;
            });
        $subjects = new RecordingAuthorizationSubjectReader(
            $this->runSubject($scope, $definition, $snapshot),
            null,
            $events,
        );
        $resolver = new RecordingAuthorizationTargetResolver(
            new CurrentReportAuthorizationTarget($definition, ReportOperation::VIEW, $snapshot),
            [],
            $events,
        );
        $authorizer = new RecordingCurrentReportScopeAuthorizer($events);
        $orchestrator = new ReportHttpAuthorizationOrchestrator(
            $connection,
            new ReportExecutionContextFactory,
            $resolver,
            $subjects,
            $authorizer,
        );

        $orchestrator->showRun($this->request(), self::RUN_ID);

        self::assertSame(
            [
                'transaction:start',
                'transaction:configured',
                'subject:run',
                'resolver:run',
                'authorize:exact',
                'transaction:end',
            ],
            $events->items,
        );
    }

    public function test_target_or_authorization_identity_mismatch_fails_before_context_publication(): void
    {
        $scope = $this->scope();
        $definition = $this->definition();
        $snapshot = $this->snapshot($scope, $definition);
        $target = new CurrentReportAuthorizationTarget($definition, ReportOperation::VIEW, $snapshot);
        $authorizer = new RecordingCurrentReportScopeAuthorizer;
        $authorizer->replacementTarget = new CurrentReportAuthorizationTarget(
            $definition,
            ReportOperation::DRILL_DOWN,
            $snapshot,
        );
        $orchestrator = $this->orchestrator(
            new RecordingAuthorizationTargetResolver($target),
            new RecordingAuthorizationSubjectReader(
                $this->runSubject($scope, $definition, $snapshot),
            ),
            $authorizer,
        );

        $this->expectException(ReportContractException::class);
        $this->expectExceptionMessage(ReportErrorCode::REPORT_SCOPE_FORBIDDEN->value);

        $orchestrator->showRun($this->request(), self::RUN_ID);
    }

    #[DataProvider('persistedTargetReplayProvider')]
    public function test_persisted_target_replay_fails_before_current_authorization(
        ReportOperation $requestedOperation,
        ReportOperation $replayedOperation,
        bool $replaceDefinition,
    ): void {
        $scope = $this->scope();
        $definition = $this->definition();
        $snapshot = $this->snapshot($scope, $definition);
        $subject = $this->runSubject($scope, $definition, $snapshot);
        $targetDefinition = $replaceDefinition ? $this->definition('report', 'c') : $definition;
        $targetSnapshot = $replayedOperation === ReportOperation::RUN
            ? null
            : $this->snapshot($scope, $targetDefinition);
        $target = new CurrentReportAuthorizationTarget($targetDefinition, $replayedOperation, $targetSnapshot);
        $authorizer = new RecordingCurrentReportScopeAuthorizer;
        $orchestrator = $this->orchestrator(
            new RecordingAuthorizationTargetResolver($target),
            new RecordingAuthorizationSubjectReader($subject),
            $authorizer,
        );

        try {
            match ($requestedOperation) {
                ReportOperation::VIEW => $orchestrator->showRun($this->request([
                    'operation' => $replayedOperation->value,
                    'definition_hash' => $targetDefinition->definitionHash->value,
                ]), self::RUN_ID),
                ReportOperation::RUN => $orchestrator->retryRun($this->request(), self::RUN_ID),
                ReportOperation::EXPORT => $orchestrator->createExport(
                    $this->request(),
                    self::RUN_ID,
                    $definition->formats[0],
                ),
                default => throw new \LogicException('unsupported_test_operation'),
            };
            self::fail('Persisted target replay must fail closed.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_SCOPE_FORBIDDEN, $exception->errorCode);
        }

        self::assertSame([], $authorizer->exactCalls);
    }

    public function test_catalog_rejects_authorization_with_a_different_complete_scope(): void
    {
        $definition = $this->definition();
        $target = new CurrentReportAuthorizationTarget($definition, ReportOperation::VIEW, null);
        $contextAuthorization = (new RecordingCurrentReportScopeAuthorizer)->authorizeForOrganization(
            17,
            41,
            new DateTimeZone('UTC'),
            $target,
        );
        $context = (new ReportExecutionContextFactory)->fromCurrentAuthorization($contextAuthorization);
        $mismatched = new CurrentReportAuthorization(
            $contextAuthorization->actor,
            new AuthorizationDecisionContext(
                'http',
                41,
                [41, 42],
                [],
                [],
                new DateTimeZone('UTC'),
                'report-http-authorization-test',
                null,
            ),
            $contextAuthorization->visibility,
            $target,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_catalog_authorization_invalid');

        new ReportCatalogAuthorization(
            $context,
            [$definition->definitionHash->value => $mismatched],
        );
    }

    public static function persistedOperationProvider(): array
    {
        return [
            'show run' => ['showRun', ReportDispatchAggregate::RUN, ReportOperation::VIEW],
            'retry run' => ['retryRun', ReportDispatchAggregate::RUN, ReportOperation::RUN],
            'cancel run' => ['cancelRun', ReportDispatchAggregate::RUN, ReportOperation::RUN],
            'rows' => ['rows', ReportDispatchAggregate::RUN, ReportOperation::VIEW],
            'drill down' => ['drillDown', ReportDispatchAggregate::RUN, ReportOperation::DRILL_DOWN],
            'create export' => ['createExport', ReportDispatchAggregate::RUN, ReportOperation::EXPORT],
            'show export' => ['showExport', ReportDispatchAggregate::EXPORT, ReportOperation::VIEW],
            'retry export' => ['retryExport', ReportDispatchAggregate::EXPORT, ReportOperation::EXPORT],
            'cancel export' => ['cancelExport', ReportDispatchAggregate::EXPORT, ReportOperation::EXPORT],
            'download' => ['download', ReportDispatchAggregate::EXPORT, ReportOperation::DOWNLOAD],
        ];
    }

    public static function persistedTargetReplayProvider(): array
    {
        return [
            'show target replayed into retry' => [ReportOperation::RUN, ReportOperation::VIEW, false],
            'run target replayed into export' => [ReportOperation::EXPORT, ReportOperation::RUN, false],
            'export target replayed into rows' => [ReportOperation::VIEW, ReportOperation::EXPORT, false],
            'current same-code definition replaces persisted revision' => [ReportOperation::VIEW, ReportOperation::VIEW, true],
        ];
    }

    private function orchestrator(
        ReportHttpAuthorizationTargetResolver $resolver,
        ReportAuthorizationSubjectReader $subjects,
        CurrentReportScopeAuthorizer $authorizer,
    ): ReportHttpAuthorizationOrchestrator {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('transaction')
            ->willReturnCallback(static fn (\Closure $callback): mixed => $callback());
        $connection->method('statement')->willReturn(true);

        return new ReportHttpAuthorizationOrchestrator(
            $connection,
            new ReportExecutionContextFactory,
            $resolver,
            $subjects,
            $authorizer,
        );
    }

    private function request(array $input = []): Request
    {
        $request = Request::create('/api/v1/admin/reports', 'POST', $input);
        $request->setUserResolver(static fn (?string $guard = null): object => new class
        {
            public function getAuthIdentifier(): int
            {
                return 17;
            }
        });
        $request->attributes->set('current_organization_id', 41);

        return $request;
    }

    private function definition(string $code = 'report', string $hash = 'a'): ReportDefinition
    {
        return (new ReportDefinitionBuilder)
            ->code($code)
            ->definitionHash(new Sha256Hash(str_repeat($hash, 64)))
            ->payload();
    }

    private function scope(): ReportScope
    {
        return new ReportScope(41, [41], [], [], new DateTimeZone('UTC'));
    }

    private function snapshot(ReportScope $scope, ReportDefinition $definition): ReportSnapshotRef
    {
        return new ReportSnapshotRef(
            'report',
            'snapshot',
            $scope,
            $definition->definitionHash,
            $definition->formulaVersion,
            new Sha256Hash(str_repeat('b', 64)),
            new DateTimeImmutable('2026-07-29T00:00:00.000000Z'),
            null,
            [],
            ReportSnapshotClassification::OPERATIONAL,
            null,
        );
    }

    private function runSubject(
        ReportScope $scope,
        ReportDefinition $definition,
        ReportSnapshotRef $snapshot,
    ): ReportAuthorizationSubject {
        return new ReportAuthorizationSubject(
            ReportDispatchAggregate::RUN,
            self::RUN_ID,
            $definition,
            $scope,
            $snapshot,
            null,
            null,
        );
    }

    private function exportSubject(
        ReportScope $scope,
        ReportDefinition $definition,
        ReportSnapshotRef $snapshot,
    ): ReportAuthorizationSubject {
        return new ReportAuthorizationSubject(
            ReportDispatchAggregate::EXPORT,
            self::EXPORT_ID,
            $definition,
            $scope,
            $snapshot,
            self::RUN_ID,
            new Sha256Hash(str_repeat('e', 64)),
            null,
            $definition->formats[0],
        );
    }
}

final class RecordingAuthorizationTargetResolver implements ReportHttpAuthorizationTargetResolver
{
    /** @var list<string> */
    public array $createRunCodes = [];

    /** @var list<string> */
    public array $runIds = [];

    /** @var list<string> */
    public array $exportIds = [];

    public array $createExportCalls = [];

    public function __construct(
        private readonly CurrentReportAuthorizationTarget $target,
        private readonly array $catalogTargets = [],
        private readonly ?AuthorizationEventRecorder $events = null,
    ) {}

    public function createRun(string $reportCode): CurrentReportAuthorizationTarget
    {
        $this->createRunCodes[] = $reportCode;

        return $this->target;
    }

    public function run(string $runId, ReportOperation $operation): CurrentReportAuthorizationTarget
    {
        $this->runIds[] = $runId;
        if ($this->events !== null) {
            $this->events->items[] = 'resolver:run';
        }

        return $this->target;
    }

    public function createExport(string $runId, ?string $format = null): CurrentReportAuthorizationTarget
    {
        $this->runIds[] = $runId;
        $this->createExportCalls[] = [$runId, $format];

        return $this->target;
    }

    public function export(string $exportId, ReportOperation $operation): CurrentReportAuthorizationTarget
    {
        $this->exportIds[] = $exportId;

        return $this->target;
    }

    public function catalog(): array
    {
        return $this->catalogTargets;
    }
}

final class RecordingAuthorizationSubjectReader implements ReportAuthorizationSubjectReader
{
    /** @var list<string> */
    public array $runIds = [];

    /** @var list<string> */
    public array $exportIds = [];

    public function __construct(
        private readonly ?ReportAuthorizationSubject $run = null,
        private readonly ?ReportAuthorizationSubject $export = null,
        private readonly ?AuthorizationEventRecorder $events = null,
    ) {}

    public function run(string $runId): ReportAuthorizationSubject
    {
        $this->runIds[] = $runId;
        if ($this->events !== null) {
            $this->events->items[] = 'subject:run';
        }

        return $this->run ?? throw new InvalidArgumentException('report_not_found');
    }

    public function export(string $exportId): ReportAuthorizationSubject
    {
        $this->exportIds[] = $exportId;

        return $this->export ?? throw new InvalidArgumentException('report_not_found');
    }
}

final class RecordingCurrentReportScopeAuthorizer implements CurrentReportScopeAuthorizer
{
    /** @var list<array{actorId:int,organizationId:int,timezone:DateTimeZone,target:CurrentReportAuthorizationTarget}> */
    public array $organizationCalls = [];

    /** @var list<array{actorId:int,scope:ReportScope,target:CurrentReportAuthorizationTarget}> */
    public array $exactCalls = [];

    /** @var list<array{actorId:int,organizationId:int,timezone:DateTimeZone,targets:list<CurrentReportAuthorizationTarget>}> */
    public array $catalogCalls = [];

    public ?CurrentReportAuthorizationTarget $replacementTarget = null;

    /** @var array<string, ReportContractException> */
    public array $failuresByDefinitionHash = [];

    /** @var array<string, ReportVisibility> */
    public array $visibilityByDefinitionHash = [];

    public function __construct(private readonly ?AuthorizationEventRecorder $events = null) {}

    public function authorizeForOrganization(
        int $actorId,
        int $organizationId,
        DateTimeZone $timezone,
        CurrentReportAuthorizationTarget $target,
    ): CurrentReportAuthorization {
        $this->organizationCalls[] = compact('actorId', 'organizationId', 'timezone', 'target');
        $failure = $this->failuresByDefinitionHash[$target->definition->definitionHash->value] ?? null;
        if ($failure instanceof ReportContractException) {
            throw $failure;
        }

        return $this->authorization(
            $actorId,
            new ReportScope($organizationId, [$organizationId], [], [], $timezone),
            $this->replacementTarget ?? $target,
        );
    }

    public function authorizeCatalog(
        int $actorId,
        int $organizationId,
        DateTimeZone $timezone,
        array $targets,
    ): ReportCatalogAuthorization {
        $this->catalogCalls[] = compact('actorId', 'organizationId', 'timezone', 'targets');
        $scope = new ReportScope($organizationId, [$organizationId], [], [], $timezone);
        $authorizations = [];
        $context = null;

        foreach ($targets as $target) {
            $failure = $this->failuresByDefinitionHash[$target->definition->definitionHash->value] ?? null;
            if ($failure instanceof ReportContractException) {
                if ($failure->errorCode === ReportErrorCode::REPORT_SCOPE_FORBIDDEN) {
                    continue;
                }

                throw $failure;
            }
            $authorization = $this->authorization($actorId, $scope, $this->replacementTarget ?? $target);
            $authorizations[$authorization->target->definition->definitionHash->value] = $authorization;
            $context ??= new ReportExecutionContext(
                $authorization->actor,
                $scope,
                $authorization->visibility,
                $authorization->decision,
            );
        }

        if (! $context instanceof ReportExecutionContext) {
            throw new InvalidArgumentException('report_catalog_authorization_invalid');
        }

        return new ReportCatalogAuthorization($context, $authorizations);
    }

    public function authorizeExact(
        int $actorId,
        ReportScope $requestedScope,
        CurrentReportAuthorizationTarget $target,
    ): CurrentReportAuthorization {
        $this->exactCalls[] = [
            'actorId' => $actorId,
            'scope' => $requestedScope,
            'target' => $target,
        ];
        if ($this->events !== null) {
            $this->events->items[] = 'authorize:exact';
        }

        return $this->authorization($actorId, $requestedScope, $this->replacementTarget ?? $target);
    }

    public function authorizeExactMany(
        int $actorId,
        ReportScope $requestedScope,
        array $targets,
    ): array {
        return array_map(
            fn (CurrentReportAuthorizationTarget $target): CurrentReportAuthorization => $this->authorizeExact(
                $actorId,
                $requestedScope,
                $target,
            ),
            $targets,
        );
    }

    private function authorization(
        int $actorId,
        ReportScope $scope,
        CurrentReportAuthorizationTarget $target,
    ): CurrentReportAuthorization {
        return new CurrentReportAuthorization(
            new ReportActor($actorId, 'active', ['reports.view', 'reports.run', 'reports.export', 'reports.download']),
            new AuthorizationDecisionContext(
                'http',
                $scope->organizationId,
                $scope->holdingOrganizationIds,
                $scope->projectIds,
                $scope->resources,
                $scope->timezone,
                'report-http-authorization-test',
                null,
            ),
            $this->visibilityByDefinitionHash[$target->definition->definitionHash->value]
                ?? new ReportVisibility(true, true, true, true, false, false, false),
            $target,
        );
    }
}

final class AuthorizationEventRecorder
{
    /** @var list<string> */
    public array $items = [];
}
