<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportPublicationRegistry;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\YamlReportManifestLoader;
use App\BusinessModules\Core\Reporting\Infrastructure\Conformance\FilesystemReportConformanceEvidenceRepository;
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
            $this->dispatches($container),
            $container->make(ReportDefinitionFactory::class),
            $evidence,
            new EligibilityServiceReportPublicationReleaseGate(
                $container->make(ReportPublicationEligibilityService::class),
            ),
            $container->make(ReportPublicationRegistry::class),
        );
    }

    public function dispatches(Container $container): ReportPublicationReleaseDispatchProfileCatalog
    {
        $profiles = self::profiles();

        return new ReportPublicationReleaseDispatchProfileCatalog([
            new ReportPublicationReleaseDispatch(
                $profiles->forCode('procurement_cycle'),
                new ProcurementCycleReleaseCandidateResolverAdapter(
                    $container->make(ProcurementCycleReleaseCandidateResolver::class),
                ),
                new ProcurementCycleReleaseBindingFactoryAdapter(
                    $container->make(ProcurementCycleReportBindingFactory::class),
                ),
            ),
        ]);
    }

    public static function profiles(): ReportPublicationReleaseProfileCatalog
    {
        return new ReportPublicationReleaseProfileCatalog([
            new ReportPublicationReleaseDispatchProfile(
                'procurement_cycle',
                'r15_release_request',
                [
                    'candidate_manifest' => 'r15-candidate-manifest.json',
                    'conformance_evidence' => 'r15-conformance-evidence.json',
                    'proof_template' => 'r15-proof-template.json',
                ],
            ),
        ]);
    }
}
