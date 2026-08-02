<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

use InvalidArgumentException;

final readonly class ProjectModelEvidenceBinding
{
    public function __construct(
        public int $buildingModelId,
        public int $organizationId,
        public int $projectId,
        public int $sessionId,
        public string $sourceVersion,
        public string $entityStableKey,
        public string $assertionStableKey,
        public ?string $correctionStableKey,
        public int $evidenceId,
        public string $candidateSource,
        public string $candidateValueFingerprint,
        public string $evidenceSourceVersion,
        public int $evidenceInvalidationVersion,
    ) {
        if ($buildingModelId < 1 || $evidenceId < 1 || $evidenceInvalidationVersion < 0) {
            throw new InvalidArgumentException('Project model evidence binding identifiers are invalid.');
        }
        ProjectModelEntity::assertScope($organizationId, $projectId, $sessionId, $sourceVersion);
        ProjectModelEntity::assertStableKey($entityStableKey, 'Evidence binding entity');
        ProjectModelEntity::assertStableKey($assertionStableKey, 'Evidence binding assertion');
        if ($correctionStableKey !== null) {
            ProjectModelEntity::assertStableKey($correctionStableKey, 'Evidence binding correction');
        }
        if (! in_array($candidateSource, ['manual_correction', 'cad', 'table', 'explicit_dimension', 'reconciled_geometry'], true)) {
            throw new InvalidArgumentException('Project model evidence binding candidate source is invalid.');
        }
        if (preg_match('/^[a-f0-9]{64}$/', $candidateValueFingerprint) !== 1) {
            throw new InvalidArgumentException('Project model evidence binding value fingerprint is invalid.');
        }
        if ($evidenceSourceVersion === '' || mb_strlen($evidenceSourceVersion) > 80) {
            throw new InvalidArgumentException('Project model evidence source version is invalid.');
        }
    }

    /** @param array<string, mixed> $value */
    public function proves(string $entityStableKey, string $assertionStableKey, ?string $correctionStableKey, string $source, array $value): bool
    {
        return $this->entityStableKey === $entityStableKey
            && $this->assertionStableKey === $assertionStableKey
            && $this->correctionStableKey === $correctionStableKey
            && $this->candidateSource === $source
            && hash_equals($this->candidateValueFingerprint, ProjectModelValueFingerprint::for($value));
    }
}
