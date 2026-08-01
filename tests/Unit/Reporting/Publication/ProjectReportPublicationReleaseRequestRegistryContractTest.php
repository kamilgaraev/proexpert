<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Publication;

use App\BusinessModules\Core\Reporting\Application\Publication\ProjectReportPublicationReleaseRequestRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportPublicationRegistry;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ProjectReportPublicationReleaseRequestRegistryContractTest extends TestCase
{
    public function testCompositionRequiresTrustedManifestHashAndRuntimePorts(): void
    {
        $constructor = (new ReflectionClass(ProjectReportPublicationReleaseRequestRegistry::class))->getConstructor();
        self::assertNotNull($constructor);
        $parameters = array_map(static fn ($parameter): string => $parameter->getName(), $constructor->getParameters());

        self::assertSame([
            'trustedDirectory',
            'officialManifestBytes',
            'officialManifestHash',
            'candidateResolver',
            'definitions',
            'bindings',
            'evidence',
            'gate',
            'publications',
        ], $parameters);
        self::assertSame(Sha256Hash::class, $constructor->getParameters()[2]->getType()?->getName());
        self::assertSame(ReportPublicationRegistry::class, $constructor->getParameters()[8]->getType()?->getName());
    }
}
