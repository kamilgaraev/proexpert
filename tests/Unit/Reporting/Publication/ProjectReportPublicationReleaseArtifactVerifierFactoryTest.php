<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Publication;

use App\BusinessModules\Core\Reporting\Application\Publication\ProjectReportPublicationReleaseArtifactVerifierFactory;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportPublicationReleaseArtifactVerifier;
use PHPUnit\Framework\TestCase;

final class ProjectReportPublicationReleaseArtifactVerifierFactoryTest extends TestCase
{
    public function test_project_owned_authority_registry_contains_only_public_verification_material(): void
    {
        $path = dirname(__DIR__, 4)
            .'/app/BusinessModules/Core/Reporting/resources/report-publication-release-authorities.v1.json';
        $payload = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);

        self::assertSame(
            ['job', 'public_key_base64', 'repository', 'workflow_ref'],
            array_keys($payload['most-ci']['reports-release-2026-01']),
        );
        self::assertInstanceOf(
            ReportPublicationReleaseArtifactVerifier::class,
            (new ProjectReportPublicationReleaseArtifactVerifierFactory)->create(),
        );
    }
}
