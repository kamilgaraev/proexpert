<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ReportSnapshotSeal
{
    public function __construct(
        public string $keyId,
        public string $algorithm,
        public Sha256Hash $sealedPayloadHash,
        public string $signature,
        public DateTimeImmutable $sealedAt,
    ) {
        if (preg_match('/^[a-z][a-z0-9_.:-]{2,127}$/D', $keyId) !== 1
            || $algorithm !== 'ed25519-sha256'
            || preg_match('/^[A-Za-z0-9_-]{86}$/D', $signature) !== 1) {
            throw new InvalidArgumentException('report_snapshot_seal_invalid');
        }

        $decoded = base64_decode(strtr($signature, '-_', '+/').'==', true);
        if ($decoded === false
            || strlen($decoded) !== 64
            || rtrim(strtr(base64_encode($decoded), '+/', '-_'), '=') !== $signature) {
            throw new InvalidArgumentException('report_snapshot_seal_invalid');
        }
    }
}
