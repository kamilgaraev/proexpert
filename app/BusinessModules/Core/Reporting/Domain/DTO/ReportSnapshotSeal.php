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
        $expectedSignatureLength = $algorithm === 'sha256' ? 43 : 86;
        $expectedDecodedLength = $algorithm === 'sha256' ? 32 : 64;
        if (preg_match('/^[a-z][a-z0-9_.:-]{2,127}$/D', $keyId) !== 1
            || !in_array($algorithm, ['sha256', 'ed25519-sha256'], true)
            || preg_match('/^[A-Za-z0-9_-]{'.$expectedSignatureLength.'}$/D', $signature) !== 1) {
            throw new InvalidArgumentException('report_snapshot_seal_invalid');
        }

        $padding = str_repeat('=', (4 - strlen($signature) % 4) % 4);
        $decoded = base64_decode(strtr($signature, '-_', '+/').$padding, true);
        if ($decoded === false
            || strlen($decoded) !== $expectedDecodedLength
            || rtrim(strtr(base64_encode($decoded), '+/', '-_'), '=') !== $signature) {
            throw new InvalidArgumentException('report_snapshot_seal_invalid');
        }
    }
}
