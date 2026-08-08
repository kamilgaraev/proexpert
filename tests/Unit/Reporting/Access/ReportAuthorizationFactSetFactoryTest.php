<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Access;

use App\BusinessModules\Core\Reporting\Application\Access\ReportAuthorizationFactSetFactory;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScopedResource;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class ReportAuthorizationFactSetFactoryTest extends TestCase
{
    public function test_organization_scope_uses_only_the_organization_fact(): void
    {
        $facts = (new ReportAuthorizationFactSetFactory)->forScope(
            17,
            new ReportScope(41, [41], [], [], new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-08-08T00:00:00+00:00'),
        );

        self::assertCount(1, $facts);
        self::assertNull($facts[0]->projectId);
        self::assertNull($facts[0]->resource);
    }

    public function test_project_scope_uses_every_project_without_an_artificial_organization_fact(): void
    {
        $facts = (new ReportAuthorizationFactSetFactory)->forScope(
            17,
            new ReportScope(41, [41], [73, 74], [], new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-08-08T00:00:00+00:00'),
        );

        self::assertSame([73, 74], array_map(static fn ($fact): ?int => $fact->projectId, $facts));
        self::assertSame([null, null], array_map(static fn ($fact) => $fact->resource, $facts));
    }

    public function test_resource_fact_replaces_its_project_fact_and_uncovered_projects_remain_required(): void
    {
        $resource = new ReportScopedResource('contract', 91, 73);
        $facts = (new ReportAuthorizationFactSetFactory)->forScope(
            17,
            new ReportScope(41, [41], [73, 74], [$resource], new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-08-08T00:00:00+00:00'),
        );

        self::assertCount(2, $facts);
        self::assertSame(73, $facts[0]->projectId);
        self::assertSame($resource, $facts[0]->resource);
        self::assertSame(74, $facts[1]->projectId);
        self::assertNull($facts[1]->resource);
    }

    public function test_each_resource_remains_an_independent_authorization_fact(): void
    {
        $first = new ReportScopedResource('contract', 91, 73);
        $second = new ReportScopedResource('contract', 92, 73);
        $facts = (new ReportAuthorizationFactSetFactory)->forScope(
            17,
            new ReportScope(41, [41], [73], [$first, $second], new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-08-08T00:00:00+00:00'),
        );

        self::assertSame([$first, $second], array_map(static fn ($fact) => $fact->resource, $facts));
    }
}
