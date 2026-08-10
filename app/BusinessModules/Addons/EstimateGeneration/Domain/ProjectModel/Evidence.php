<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel;

use InvalidArgumentException;

final readonly class Evidence
{
    public function __construct(
        public string $id,
        public int $organizationId,
        public int $projectId,
        public int $sessionId,
        public string $sourceVersion,
        public string $sourceArtifactId,
        public string $sourceType,
        public ?int $page = null,
        public ?array $region = null,
        public ?string $nativeReference = null,
    ) {
        ProjectModelInvariant::scope($organizationId, $projectId, $sessionId, $sourceVersion);
        ProjectModelInvariant::id($id, 'Evidence');
        ProjectModelInvariant::id($sourceArtifactId, 'Evidence artifact');
        ProjectModelInvariant::id($sourceType, 'Evidence source type');
        if ($page !== null && $page <= 0) {
            throw new InvalidArgumentException('Evidence page is invalid.');
        }
        if ($region !== null) {
            $this->assertRegion($region);
        }
        if ($nativeReference !== null && (trim($nativeReference) === '' || strlen($nativeReference) > 500)) {
            throw new InvalidArgumentException('Evidence native reference is invalid.');
        }
        if ($page === null && $region === null && $nativeReference === null) {
            throw new InvalidArgumentException('Evidence has no exact source locator.');
        }
    }

    private function assertRegion(array $region): void
    {
        if (array_keys($region) !== ['x', 'y', 'width', 'height']) {
            throw new InvalidArgumentException('Evidence region is invalid.');
        }
        foreach ($region as $key => $value) {
            if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value) || $value < 0
                || ($key !== 'x' && $key !== 'y' && $value <= 0)) {
                throw new InvalidArgumentException('Evidence region is invalid.');
            }
        }
    }
}
