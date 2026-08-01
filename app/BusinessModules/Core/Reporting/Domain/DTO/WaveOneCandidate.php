<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

final readonly class WaveOneCandidate
{
    public function __construct(
        public int $ordinal,
        public string $groupId,
        public string $code,
        public string $family,
        public string $sourceStatus,
        public string $publication,
    ) {}
}
