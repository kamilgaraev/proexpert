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
        public ?string $producerName = null,
        public ?string $sourceReference = null,
        public ?string $choiceHash = null,
        public ?string $fingerprint = null,
        public ?string $documentRole = null,
        public ?LiteralFactEvidence $literalFact = null,
        public ?FactSourceLocator $factSource = null,
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
        if ($choiceHash !== null && preg_match('/\A[a-f0-9]{64}\z/', $choiceHash) !== 1) {
            throw new InvalidArgumentException('Evidence choice hash is invalid.');
        }
        if ($fingerprint !== null && preg_match('/\A[a-f0-9]{64}\z/', $fingerprint) !== 1) {
            throw new InvalidArgumentException('Evidence fingerprint is invalid.');
        }
        if ($documentRole !== null && SourceDocumentRole::normalize($documentRole) !== $documentRole) {
            throw new InvalidArgumentException('Evidence document role is invalid.');
        }
    }

    /** Presentation of an existing source; does not measure or confirm the fact. */
    public function sourceDisplayLocator(): array
    {
        $region = $this->factSource !== null ? $this->factSource->region() : $this->region;
        $native = match ($this->factSource?->value['kind'] ?? null) {
            'polygon' => null,
            'native' => $this->factSource->value['value'],
            default => $this->nativeReference,
        };

        return ['region' => $region, 'native_reference' => $native,
            'coordinate_space' => $this->factSource?->region() !== null ? 'normalized_source_v1' : null];
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
