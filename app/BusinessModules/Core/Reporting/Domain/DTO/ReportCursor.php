<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ReportCursor
{
    public function __construct(
        public string $token,
        public string $runId,
        public Sha256Hash $queryHash,
        public Sha256Hash $sourceHash,
        public ReportWindowSort $sort,
        public DateTimeImmutable $expiresAt,
    ) {
        if (trim($token) === '' || !self::isUlid($runId) || $expiresAt <= new DateTimeImmutable()) {
            throw new InvalidArgumentException('report_cursor_invalid');
        }
    }

    private static function isUlid(string $value): bool
    {
        return preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/', $value) === 1;
    }
}
