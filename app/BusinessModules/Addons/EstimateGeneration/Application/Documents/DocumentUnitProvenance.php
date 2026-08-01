<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use InvalidArgumentException;

final readonly class DocumentUnitProvenance
{
    /**
     * @param array<string, scalar|null> $locator
     */
    private function __construct(
        public string $sourceKind,
        public string $sourceVersion,
        public string $coordinateSpace,
        public string $artifactPath,
        public string $artifactSha256,
        public string $artifactVersionId,
    ) {}

    /**
     * @param array<string, scalar|null> $locator
     */
    public static function fromLocator(DocumentUnitType $type, string $sourceVersion, array $locator): self
    {
        $sourceKind = self::string($locator, 'source_kind');
        $declaredSourceVersion = self::string($locator, 'source_version');
        $coordinateSpace = self::string($locator, 'coordinate_space');
        $artifactPath = self::string($locator, 'artifact_path');
        $artifactSha256 = self::string($locator, 'artifact_sha256');
        $artifactVersionId = self::string($locator, 'artifact_version_id');

        if ($sourceKind !== $type->sourceKind()
            || $declaredSourceVersion !== $sourceVersion
            || $coordinateSpace !== $type->coordinateSpace()
            || strlen($artifactPath) > 2048
            || preg_match('/\\Asha256:[a-f0-9]{64}\\z/', $artifactSha256) !== 1
            || preg_match('/\\A[\\x21-\\x7e]{1,1024}\\z/D', $artifactVersionId) !== 1) {
            throw new InvalidArgumentException('Document unit provenance is invalid.');
        }

        return new self(
            $sourceKind,
            $declaredSourceVersion,
            $coordinateSpace,
            $artifactPath,
            $artifactSha256,
            $artifactVersionId,
        );
    }

    /** @return array{source_kind: string, source_version: string, coordinate_space: string, artifact_path: string, artifact_sha256: string, artifact_version_id: string} */
    public function toArray(): array
    {
        return [
            'source_kind' => $this->sourceKind,
            'source_version' => $this->sourceVersion,
            'coordinate_space' => $this->coordinateSpace,
            'artifact_path' => $this->artifactPath,
            'artifact_sha256' => $this->artifactSha256,
            'artifact_version_id' => $this->artifactVersionId,
        ];
    }

    /**
     * @param array<string, scalar|null> $locator
     */
    private static function string(array $locator, string $key): string
    {
        $value = $locator[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('Document unit provenance is invalid.');
        }

        return $value;
    }
}
