<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class ReportPublicationReleaseIdentity
{
    public function __construct(
        public string $gitSha,
        public DateTimeImmutable $createdAt,
        public string $approverIdentity,
    ) {
        if (preg_match('/^[a-f0-9]{40}$/D', $gitSha) !== 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9@._:-]{2,127}$/D', $approverIdentity) !== 1
            || $createdAt->getOffset() !== 0) {
            throw new InvalidArgumentException('report_publication_release_identity_invalid');
        }
    }

    public function createdAtUtc(): string
    {
        return $this->createdAt
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.u\Z');
    }
}
