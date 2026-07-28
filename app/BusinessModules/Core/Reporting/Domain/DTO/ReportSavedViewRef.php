<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use InvalidArgumentException;

final readonly class ReportSavedViewRef
{
    public function __construct(
        public string $id,
        public int $revision,
        public Sha256Hash $hash,
    ) {
        if (preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $id) !== 1 || $revision < 1) {
            throw new InvalidArgumentException('report_saved_view_reference_invalid');
        }
    }
}
