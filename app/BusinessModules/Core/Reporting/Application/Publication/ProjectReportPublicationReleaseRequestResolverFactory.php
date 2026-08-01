<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

use Illuminate\Contracts\Container\Container;

final readonly class ProjectReportPublicationReleaseRequestResolverFactory implements ReportPublicationReleaseRequestResolverFactory
{
    public function __construct(
        private Container $container,
        private ProjectReportPublicationReleaseRequestRegistryFactory $registryFactory,
    ) {}

    public function create(string $trustedDirectory): ReportPublicationReleaseRequestResolver
    {
        return $this->registryFactory->create($this->container, $trustedDirectory, $trustedDirectory);
    }

    public function dispatchForArtifactName(string $artifactName): ReportPublicationReleaseDispatch
    {
        return $this->registryFactory
            ->dispatches($this->container)
            ->forArtifactName($artifactName);
    }
}
