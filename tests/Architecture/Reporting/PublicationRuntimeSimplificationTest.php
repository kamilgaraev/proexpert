<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use PHPUnit\Framework\TestCase;

final class PublicationRuntimeSimplificationTest extends TestCase
{
    public function test_runtime_has_no_release_signing_ingestion_or_artifact_transfer_surface(): void
    {
        $root = dirname(__DIR__, 3);
        $provider = $this->source($root.'/app/BusinessModules/Core/Reporting/ReportingCatalogServiceProvider.php');
        $registry = $this->source($root.'/app/BusinessModules/Core/Reporting/Infrastructure/Publication/EloquentReportPublicationRegistry.php');

        foreach ([
            'ReportPublicationRelease',
            'ReportPublicationEligibilityService',
            'ProjectReportPublicationRelease',
            'ReportingArtifactTransferService',
            'Ed25519ReportPublication',
        ] as $removedSymbol) {
            self::assertStringNotContainsString($removedSymbol, $provider);
            self::assertStringNotContainsString($removedSymbol, $registry);
        }

        self::assertStringNotContainsString('release_artifact_json', $registry);
        self::assertStringNotContainsString('report_publication_promote', $registry);
        self::assertStringContainsString("table('public.report_publications')", $registry);
        self::assertStringContainsString("where('status', ReportPublicationStatus::PUBLISHED->value)", $registry);
    }

    public function test_only_product_definition_helpers_remain_in_publication_application_namespace(): void
    {
        $directory = dirname(__DIR__, 3).'/app/BusinessModules/Core/Reporting/Application/Publication';
        $files = array_map('basename', glob($directory.'/*.php') ?: []);
        sort($files, SORT_STRING);

        self::assertSame([
            'ReportDefinitionCanonicalProjector.php',
            'ReportDefinitionSemanticFingerprint.php',
            'ReportDefinitionVersionPolicy.php',
        ], $files);
    }

    public function test_no_release_evidence_application_layer_remains(): void
    {
        $root = dirname(__DIR__, 3);
        $evidenceFiles = glob($root.'/app/BusinessModules/Core/Reporting/Application/Evidence/*.php') ?: [];

        self::assertSame([], $evidenceFiles);
        foreach ([
            'ReportReleaseGateBundle.php',
            'ReportQualityGateEvidence.php',
        ] as $removedFile) {
            self::assertFileDoesNotExist(
                $root.'/app/BusinessModules/Core/Reporting/Domain/DTO/'.$removedFile,
            );
        }
    }

    private function source(string $path): string
    {
        $source = file_get_contents($path);
        self::assertIsString($source);

        return $source;
    }
}
