<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportConformanceEvidenceRepository;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportPublicationRegistry;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;
use App\BusinessModules\Core\Reporting\Infrastructure\Conformance\FilesystemReportConformanceEvidenceRepository;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\YamlReportManifestLoader;
use App\BusinessModules\Core\Reporting\Infrastructure\Validation\Draft202012SchemaValidator;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\CiEvidence\ProcurementCycleReleaseCandidateResolver;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Services\ProcurementCycleReportBindingFactory;
use Illuminate\Contracts\Container\Container;
use RuntimeException;

final class ProjectReportPublicationReleaseRequestRegistryFactory
{
    public function create(Container $container, string $trustedDirectory, string $evidenceRoot): ProjectReportPublicationReleaseRequestRegistry
    {
        $manifestPath = dirname(__DIR__, 2).'/resources/official-document-catalog.v1.yaml';
        $manifestBytes = file_get_contents($manifestPath);
        if (! is_string($manifestBytes) || $manifestBytes === '') {
            throw new RuntimeException('report_publication_official_manifest_unavailable');
        }
        $manifest = $container->make(YamlReportManifestLoader::class)->loadOfficial(
            $manifestPath,
            dirname(__DIR__, 2).'/resources/official-document-catalog.v1.schema.json',
        );
        if (! hash_equals($manifest->bytesHash->value, hash('sha256', $manifestBytes))) {
            throw new RuntimeException('report_publication_official_manifest_hash_invalid');
        }

        $evidence = new FilesystemReportConformanceEvidenceRepository(
            $evidenceRoot,
            $container->make(Draft202012SchemaValidator::class),
        );

        return new ProjectReportPublicationReleaseRequestRegistry(
            $trustedDirectory,
            $manifestBytes,
            new Sha256Hash(hash('sha256', $manifestBytes)),
            $container->make(ProcurementCycleReleaseCandidateResolver::class),
            $container->make(ReportDefinitionFactory::class),
            $container->make(ProcurementCycleReportBindingFactory::class),
            $evidence,
            new EligibilityServiceReportPublicationReleaseGate(
                $container->make(ReportPublicationEligibilityService::class),
            ),
            $container->make(ReportPublicationRegistry::class),
        );
    }
}
