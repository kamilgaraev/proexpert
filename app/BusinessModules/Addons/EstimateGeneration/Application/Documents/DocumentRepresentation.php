<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use InvalidArgumentException;

final readonly class DocumentRepresentation
{
    public const SCHEMA_VERSION = 1;

    public array $resourceUsage;

    public function __construct(
        public DocumentSourceVersion $source,
        public array $nativeStructure,
        public string $visualArtifactPath,
        public string $coordinateSpace,
        public DocumentRepresentationCapabilities $capabilities,
        public DocumentCoordinateTransform $coordinates,
        array $resourceUsage,
    ) {
        if ($visualArtifactPath === '' || $coordinateSpace === '') {
            throw new InvalidArgumentException('Document representation is incomplete.');
        }
        if (preg_match('#^org-[1-9][0-9]*/#D', $visualArtifactPath) !== 1) {
            throw new InvalidArgumentException('Document representation artifact is outside organization storage.');
        }
        $limits = new DocumentRepresentationResourceLimits;
        $this->resourceUsage = $limits->canonicalize($resourceUsage);
        $limits->assertWithin($this->resourceUsage);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'source_version' => $this->source->value,
            'format' => $this->capabilities->format,
            'native_structure' => $this->nativeStructure,
            'visual_artifact_path' => $this->visualArtifactPath,
            'coordinate_space' => $this->coordinateSpace,
            'source_bounds' => $this->coordinates->bounds(),
            'capabilities' => $this->capabilities->toArray(),
            'resource_usage' => $this->resourceUsage,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (($data['schema_version'] ?? null) !== self::SCHEMA_VERSION
            || ! is_string($data['source_version'] ?? null)
            || ! is_string($data['format'] ?? null)
            || ! is_array($data['native_structure'] ?? null)
            || ! is_string($data['visual_artifact_path'] ?? null)
            || ! is_string($data['coordinate_space'] ?? null)
            || ! is_array($data['source_bounds'] ?? null)
            || ! is_array($data['capabilities'] ?? null)
            || ! is_array($data['resource_usage'] ?? null)) {
            throw new InvalidArgumentException('Document representation payload is invalid.');
        }

        return new self(
            DocumentSourceVersion::fromString($data['source_version']),
            $data['native_structure'],
            $data['visual_artifact_path'],
            $data['coordinate_space'],
            DocumentRepresentationCapabilities::fromArray($data['format'], $data['capabilities']),
            DocumentCoordinateTransform::fromBounds($data['source_bounds']),
            $data['resource_usage'],
        );
    }
}
