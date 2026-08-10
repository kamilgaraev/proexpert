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
        public array $capabilities,
    ) {
        if ($visualArtifactPath === '' || $coordinateSpace === '' || $capabilities === []) {
            throw new InvalidArgumentException('Document representation is incomplete.');
        }

        foreach ($capabilities as $capability => $status) {
            if (! is_string($capability) || $capability === '' || ! is_string($status) || $status === '') {
                throw new InvalidArgumentException('Document representation capability is invalid.');
            }
        }
    }
}
