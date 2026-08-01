<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Publication;

use App\BusinessModules\Core\Reporting\Application\Publication\ProjectReportPublicationReleaseRequestRegistry;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationReleaseBindingFactory;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationReleaseCandidateResolver;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationReleaseDispatch;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationReleaseDispatchProfile;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationReleaseDispatchProfileCatalog;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationReleaseEligibilityGate;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationReleaseRequest;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportConformanceEvidenceRepository;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportPublicationRegistry;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ProjectReportPublicationReleaseRequestRegistryContractTest extends TestCase
{
    public function test_composition_requires_trusted_manifest_hash_and_runtime_ports(): void
    {
        $constructor = (new ReflectionClass(ProjectReportPublicationReleaseRequestRegistry::class))->getConstructor();
        self::assertNotNull($constructor);
        $parameters = array_map(static fn ($parameter): string => $parameter->getName(), $constructor->getParameters());

        self::assertSame([
            'trustedDirectory',
            'officialManifestBytes',
            'officialManifestHash',
            'dispatches',
            'definitions',
            'evidence',
            'gate',
            'publications',
        ], $parameters);
        self::assertSame(Sha256Hash::class, $constructor->getParameters()[2]->getType()?->getName());
        self::assertSame(ReportPublicationReleaseDispatchProfileCatalog::class, $constructor->getParameters()[3]->getType()?->getName());
        self::assertSame(ReportPublicationRegistry::class, $constructor->getParameters()[7]->getType()?->getName());
    }

    public function test_rejects_a_foreign_code_before_the_selected_feature_resolver_is_called(): void
    {
        $resolver = new class implements ReportPublicationReleaseCandidateResolver
        {
            public int $calls = 0;

            public function resolve(string $trustedDirectory, ReportPublicationReleaseRequest $request): array
            {
                $this->calls++;

                return [];
            }
        };
        $registry = new ProjectReportPublicationReleaseRequestRegistry(
            sys_get_temp_dir(),
            '{}',
            new Sha256Hash(hash('sha256', '{}')),
            new ReportPublicationReleaseDispatchProfileCatalog([
                new ReportPublicationReleaseDispatch(
                    new ReportPublicationReleaseDispatchProfile(
                        'procurement_cycle',
                        'r15_release_request',
                        [
                            'candidate_manifest' => 'r15-candidate-manifest.json',
                            'conformance_evidence' => 'r15-conformance-evidence.json',
                            'proof_template' => 'r15-proof-template.json',
                        ],
                    ),
                    $resolver,
                    $this->createStub(ReportPublicationReleaseBindingFactory::class),
                ),
            ]),
            new ReportDefinitionFactory,
            $this->createStub(ReportConformanceEvidenceRepository::class),
            $this->createStub(ReportPublicationReleaseEligibilityGate::class),
            $this->createStub(ReportPublicationRegistry::class),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_publication_release_input_invalid');
        try {
            $registry->resolve(ReportPublicationReleaseRequest::fromArray([
                'request_id' => 'r15_release_request',
                'schema_version' => '1.0.0',
                'code' => 'other_cycle',
                'commit_sha' => str_repeat('a', 40),
                'proof_sha256' => str_repeat('b', 64),
                'artifact_paths' => [
                    'candidate_manifest' => 'r15-candidate-manifest.json',
                    'conformance_evidence' => 'r15-conformance-evidence.json',
                    'proof_template' => 'r15-proof-template.json',
                ],
            ]));
        } finally {
            self::assertSame(0, $resolver->calls);
        }
    }
}
