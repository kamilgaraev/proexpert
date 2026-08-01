<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use InvalidArgumentException;

final readonly class WaveOneCandidateManifest
{
    private array $candidates;

    public function __construct(
        public string $catalog,
        public string $contractVersion,
        public Sha256Hash $bytesHash,
        array $candidates,
    ) {
        if ($catalog !== 'wave-1-candidates.v1'
            || $contractVersion !== '1.0.0'
            || count($candidates) !== 12) {
            throw new InvalidArgumentException('wave_one_candidate_manifest_invalid');
        }

        foreach ($candidates as $candidate) {
            if (! $candidate instanceof WaveOneCandidate) {
                throw new InvalidArgumentException('wave_one_candidate_manifest_invalid');
            }
        }

        $this->candidates = $candidates;
    }

    public function ordered(): array
    {
        return $this->candidates;
    }
}
