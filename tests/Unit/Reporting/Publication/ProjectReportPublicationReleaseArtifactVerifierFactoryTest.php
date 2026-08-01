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
            ['environment', 'event_name', 'job', 'public_key_base64', 'ref', 'repository', 'workflow_ref'],
            array_keys($payload['most-ci']['reports-release-2026-01']),
        );
        self::assertSame(
            [
                'environment' => 'report-publication-release',
                'event_name' => 'push',
                'job' => 'report-publication-release-artifact',
                'ref' => 'refs/heads/main',
                'repository' => 'kamilgaraev/proexpert',
                'workflow_ref' => '.github/workflows/notification-concurrency.yml@refs/heads/main',
            ],
            array_diff_key(
                $payload['most-ci']['reports-release-2026-01'],
                ['public_key_base64' => true],
            ),
        );
        self::assertInstanceOf(
            ReportPublicationReleaseArtifactVerifier::class,
            (new ProjectReportPublicationReleaseArtifactVerifierFactory)->create(),
        );
    }

    public function test_release_issuer_derives_protected_provenance_from_github_context(): void
    {
        $script = file_get_contents(
            dirname(__DIR__, 4).'/scripts/issue-report-publication-release.php',
        );
        self::assertIsString($script);

        foreach ([
            'GITHUB_ACTOR_ID',
            'GITHUB_EVENT_NAME',
            'GITHUB_JOB',
            'GITHUB_REF',
            'GITHUB_REPOSITORY',
            'GITHUB_RUN_ATTEMPT',
            'GITHUB_RUN_ID',
            'GITHUB_SHA',
            'GITHUB_WORKFLOW_REF',
            'REPORT_PUBLICATION_RELEASE_ENVIRONMENT',
            'REPORT_PUBLICATION_RELEASE_SECRET_KEY_BASE64',
        ] as $variable) {
            self::assertStringContainsString($variable, $script);
        }
        self::assertStringNotContainsString('most/backend', $script);
    }
}
