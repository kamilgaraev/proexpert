<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use InvalidArgumentException;

final readonly class DocumentRepresentation
{
    public function __construct(
        public DocumentSourceVersion $source,
        public array $nativeStructure,
        public string $visualArtifactPath,
        public string $coordinateSpace,
        public DocumentRepresentationCapabilities $capabilities,
        public DocumentCoordinateTransform $coordinates,
        public array $resourceUsage,
    ) {
        if ($visualArtifactPath === '' || $coordinateSpace === '') {
            throw new InvalidArgumentException('Document representation is incomplete.');
        }
        if (preg_match('#^org-[1-9][0-9]*/#D', $visualArtifactPath) !== 1) {
            throw new InvalidArgumentException('Document representation artifact is outside organization storage.');
        }
        (new DocumentRepresentationResourceLimits())->assertWithin($resourceUsage);
    }
}
