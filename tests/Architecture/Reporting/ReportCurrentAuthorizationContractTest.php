<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use App\BusinessModules\Core\Reporting\Application\Access\CurrentReportAuthorizationFacts;
use App\BusinessModules\Core\Reporting\Application\Access\CurrentReportPermissionDecision;
use App\BusinessModules\Core\Reporting\Application\Access\ReportScopedResourceAccessDecision;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorization;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ReportCurrentAuthorizationContractTest extends TestCase
{
    public function test_atomic_values_are_final_readonly_and_closed(): void
    {
        $expected = [
            CurrentReportAuthorizationFacts::class => ['channel', 'actorId', 'organizationId', 'projectId', 'resource', 'occurredAt'],
            CurrentReportPermissionDecision::class => ['actorId', 'permission', 'organizationId', 'projectId', 'resource', 'granted'],
            ReportScopedResourceAccessDecision::class => ['actorId', 'organizationId', 'projectId', 'kind', 'id', 'granted'],
            CurrentReportAuthorization::class => ['actor', 'decision', 'visibility', 'target'],
        ];
        foreach ($expected as $class => $properties) {
            $reflection = new ReflectionClass($class);
            self::assertTrue($reflection->isFinal());
            self::assertTrue($reflection->isReadOnly());
            self::assertSame($properties, array_map(static fn ($property): string => $property->getName(), $reflection->getProperties()));
        }
    }

    public function test_reporting_production_contains_no_untyped_resource_symbols_except_cutover_migration(): void
    {
        $root = dirname(__DIR__, 3).'/app/BusinessModules/Core/Reporting';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            self::assertIsString($source);
            foreach (['resourceIds', 'resource_ids', 'scope_resource_ids', 'allowed_resource_ids'] as $symbol) {
                self::assertStringNotContainsString($symbol, $source, $file->getPathname());
            }
        }
    }
}
