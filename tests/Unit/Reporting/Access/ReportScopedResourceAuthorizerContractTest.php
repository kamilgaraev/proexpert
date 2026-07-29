<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Access;

use App\BusinessModules\Core\Reporting\Application\Access\CurrentReportAuthorizationFacts;
use App\BusinessModules\Core\Reporting\Application\Access\ReportScopedResourceAccessDecision;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportScopedResourceAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScopedResource;
use App\BusinessModules\Core\Reporting\Infrastructure\Access\LaravelReportScopedResourceAuthorizerRegistry;
use App\Models\User;
use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class ReportScopedResourceAuthorizerContractTest extends TestCase
{
    public function test_empty_registry_accepts_empty_scope(): void
    {
        (new LaravelReportScopedResourceAuthorizerRegistry([]))->authorizeAll(
            $this->actor(5),
            2,
            [],
            $this->occurredAt(),
        );

        self::addToAssertionCount(1);
    }

    public function test_exact_adapter_and_decision_identity_are_accepted(): void
    {
        (new LaravelReportScopedResourceAuthorizerRegistry([new ContractResourceHandler('task')]))->authorizeAll(
            $this->actor(5),
            2,
            [$this->resource()],
            $this->occurredAt(),
        );

        self::addToAssertionCount(1);
    }

    #[DataProvider('invalidAdapterRegistries')]
    public function test_invalid_duplicate_and_wildcard_adapters_are_rejected(array $handlers): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_resource_authorizer_invalid');

        new LaravelReportScopedResourceAuthorizerRegistry($handlers);
    }

    public static function invalidAdapterRegistries(): array
    {
        return [
            'non-adapter' => [[new \stdClass]],
            'invalid kind' => [[new ContractResourceHandler('Task')]],
            'duplicate exact kind' => [[new ContractResourceHandler('task'), new ContractResourceHandler('task')]],
            'all wildcard' => [[new ContractResourceHandler('all')]],
            'generic wildcard' => [[new ContractResourceHandler('generic')]],
        ];
    }

    public function test_unknown_resource_kind_is_normalized_to_scope_forbidden(): void
    {
        $this->assertScopeForbidden(
            static fn (): mixed => (new LaravelReportScopedResourceAuthorizerRegistry([]))->authorizeAll(
                self::newActor(5),
                2,
                [new ReportScopedResource('task', 11, 7)],
                new DateTimeImmutable('2026-07-29T12:00:00+00:00'),
            ),
            InvalidArgumentException::class,
        );
    }

    #[DataProvider('identityMutations')]
    public function test_each_decision_identity_mutation_is_normalized_to_scope_forbidden(Closure $mutation): void
    {
        $handler = new ContractResourceHandler('task', $mutation);

        $this->assertScopeForbidden(
            fn (): mixed => (new LaravelReportScopedResourceAuthorizerRegistry([$handler]))->authorizeAll(
                $this->actor(5),
                2,
                [$this->resource()],
                $this->occurredAt(),
            ),
            InvalidArgumentException::class,
        );
    }

    public static function identityMutations(): array
    {
        return [
            'actor id' => [static fn (ReportScopedResourceAccessDecision $decision): ReportScopedResourceAccessDecision => self::decision($decision, actorId: 6)],
            'organization id' => [static fn (ReportScopedResourceAccessDecision $decision): ReportScopedResourceAccessDecision => self::decision($decision, organizationId: 3)],
            'project id' => [static fn (ReportScopedResourceAccessDecision $decision): ReportScopedResourceAccessDecision => self::decision($decision, projectId: 8)],
            'kind' => [static fn (ReportScopedResourceAccessDecision $decision): ReportScopedResourceAccessDecision => self::decision($decision, kind: 'other')],
            'resource id' => [static fn (ReportScopedResourceAccessDecision $decision): ReportScopedResourceAccessDecision => self::decision($decision, id: 12)],
        ];
    }

    public function test_first_actor_decision_cannot_be_replayed_for_second_actor(): void
    {
        $handler = new ReplayingResourceHandler;
        $registry = new LaravelReportScopedResourceAuthorizerRegistry([$handler]);
        $registry->authorizeAll($this->actor(5), 2, [$this->resource()], $this->occurredAt());

        $this->assertScopeForbidden(
            fn (): mixed => $registry->authorizeAll($this->actor(6), 2, [$this->resource()], $this->occurredAt()),
            InvalidArgumentException::class,
        );
    }

    #[DataProvider('handlerFailures')]
    public function test_handler_exceptions_are_normalized_to_scope_forbidden(Throwable $failure): void
    {
        $handler = new ContractResourceHandler('task', failure: $failure);

        $this->assertScopeForbidden(
            fn (): mixed => (new LaravelReportScopedResourceAuthorizerRegistry([$handler]))->authorizeAll(
                $this->actor(5),
                2,
                [$this->resource()],
                $this->occurredAt(),
            ),
            $failure::class,
        );
    }

    public static function handlerFailures(): array
    {
        return [
            'contract exception' => [ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR)],
            'runtime exception' => [new RuntimeException('adapter_failure')],
        ];
    }

    public function test_explicit_denial_is_normalized_to_scope_forbidden(): void
    {
        $handler = new ContractResourceHandler(
            'task',
            static fn (ReportScopedResourceAccessDecision $decision): ReportScopedResourceAccessDecision => self::decision(
                $decision,
                granted: false,
            ),
        );

        $this->assertScopeForbidden(
            fn (): mixed => (new LaravelReportScopedResourceAuthorizerRegistry([$handler]))->authorizeAll(
                $this->actor(5),
                2,
                [$this->resource()],
                $this->occurredAt(),
            ),
            InvalidArgumentException::class,
        );
    }

    private function actor(int $id): User
    {
        return self::newActor($id);
    }

    private static function newActor(int $id): User
    {
        $actor = new User;
        $actor->id = $id;

        return $actor;
    }

    private function resource(): ReportScopedResource
    {
        return new ReportScopedResource('task', 11, 7);
    }

    private function occurredAt(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-07-29T12:00:00+00:00');
    }

    private function assertScopeForbidden(Closure $operation, string $previousClass): void
    {
        try {
            $operation();
            self::fail('Resource authorization must fail closed.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_SCOPE_FORBIDDEN, $exception->errorCode);
            self::assertInstanceOf($previousClass, $exception->getPrevious());
        }
    }

    private static function decision(
        ReportScopedResourceAccessDecision $decision,
        ?int $actorId = null,
        ?int $organizationId = null,
        ?int $projectId = null,
        ?string $kind = null,
        ?int $id = null,
        ?bool $granted = null,
    ): ReportScopedResourceAccessDecision {
        return new ReportScopedResourceAccessDecision(
            $actorId ?? $decision->actorId,
            $organizationId ?? $decision->organizationId,
            $projectId ?? $decision->projectId,
            $kind ?? $decision->kind,
            $id ?? $decision->id,
            $granted ?? $decision->granted,
        );
    }
}

final class ContractResourceHandler implements ReportScopedResourceAuthorizer
{
    public function __construct(
        private readonly string $resourceKind,
        private readonly ?Closure $mutation = null,
        private readonly ?Throwable $failure = null,
    ) {}

    public function kind(): string
    {
        return $this->resourceKind;
    }

    public function authorize(
        User $actor,
        int $organizationId,
        ReportScopedResource $resource,
        CurrentReportAuthorizationFacts $facts,
    ): ReportScopedResourceAccessDecision {
        if ($this->failure instanceof Throwable) {
            throw $this->failure;
        }

        $decision = new ReportScopedResourceAccessDecision(
            (int) $actor->getAuthIdentifier(),
            $organizationId,
            $resource->projectId,
            $resource->kind,
            $resource->id,
            true,
        );

        return $this->mutation instanceof Closure ? ($this->mutation)($decision) : $decision;
    }
}

final class ReplayingResourceHandler implements ReportScopedResourceAuthorizer
{
    private ?ReportScopedResourceAccessDecision $firstDecision = null;

    public function kind(): string
    {
        return 'task';
    }

    public function authorize(
        User $actor,
        int $organizationId,
        ReportScopedResource $resource,
        CurrentReportAuthorizationFacts $facts,
    ): ReportScopedResourceAccessDecision {
        return $this->firstDecision ??= new ReportScopedResourceAccessDecision(
            (int) $actor->getAuthIdentifier(),
            $organizationId,
            $resource->projectId,
            $resource->kind,
            $resource->id,
            true,
        );
    }
}
