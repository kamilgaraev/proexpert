<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Contracts;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScopedResource;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ReportScopedResourceContractTest extends TestCase
{
    public function test_resource_validates_and_exposes_closed_canonical_identity(): void
    {
        $resource = new ReportScopedResource('warehouse_item', 7, 3);

        self::assertSame([
            'kind' => 'warehouse_item',
            'id' => 7,
            'project_id' => 3,
        ], $resource->canonicalIdentity());

        foreach ([['*', 1, null], ['all', 0, null], ['task', 1, 0]] as [$kind, $id, $projectId]) {
            try {
                new ReportScopedResource($kind, $id, $projectId);
                self::fail('Invalid resource accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_scope_sorts_typed_resources_and_rejects_duplicates_or_scalars(): void
    {
        $scope = new ReportScope(1, [1], [9], [
            new ReportScopedResource('task', 2, 9),
            new ReportScopedResource('asset', 2, null),
            new ReportScopedResource('task', 1, null),
        ], new DateTimeZone('UTC'));

        self::assertSame([
            ['kind' => 'asset', 'id' => 2, 'project_id' => null],
            ['kind' => 'task', 'id' => 1, 'project_id' => null],
            ['kind' => 'task', 'id' => 2, 'project_id' => 9],
        ], array_map(static fn (ReportScopedResource $resource): array => $resource->canonicalIdentity(), $scope->resources));
        self::assertArrayHasKey('resources', $scope->canonicalIdentity());
        self::assertArrayNotHasKey('resource_ids', $scope->canonicalIdentity());

        foreach ([
            [1],
            [new ReportScopedResource('task', 1, null), new ReportScopedResource('task', 1, null)],
        ] as $resources) {
            try {
                new ReportScope(1, [1], [], $resources, new DateTimeZone('UTC'));
                self::fail('Invalid resource scope accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
