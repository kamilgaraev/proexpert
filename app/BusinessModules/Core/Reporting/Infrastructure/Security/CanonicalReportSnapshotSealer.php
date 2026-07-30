<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Security;

use App\BusinessModules\Core\Reporting\Application\Execution\ReportSnapshotSealVerificationInput;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotSeal;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class CanonicalReportSnapshotSealer
{
    private string $secretKey;

    public function __construct(
        string $privateKey,
        private string $keyId,
    ) {
        if (! function_exists('sodium_crypto_sign_detached')
            || preg_match('/^[a-z][a-z0-9_.:-]{2,127}$/D', $keyId) !== 1) {
            throw new InvalidArgumentException('report_snapshot_signing_key_invalid');
        }
        $decoded = self::decodeCanonical($privateKey);
        if ($decoded === null) {
            throw new InvalidArgumentException('report_snapshot_signing_key_invalid');
        }
        $this->secretKey = $decoded;
    }

    public function seal(
        string $snapshotId,
        string $snapshotKind,
        DateTimeImmutable $generatedAt,
        Sha256Hash $sourceHash,
        DateTimeImmutable $sealedAt,
    ): ReportSnapshotSeal {
        $placeholder = new ReportSnapshotSeal(
            $this->keyId,
            'ed25519-sha256',
            $sourceHash,
            self::encode(str_repeat("\0", 64)),
            $sealedAt,
        );
        $input = new ReportSnapshotSealVerificationInput(
            $placeholder,
            $snapshotId,
            $snapshotKind,
            ReportSnapshotClassification::OFFICIAL,
            $generatedAt,
            $sourceHash,
        );

        return new ReportSnapshotSeal(
            $this->keyId,
            'ed25519-sha256',
            $sourceHash,
            self::encode(sodium_crypto_sign_detached($input->signedBytes(), $this->secretKey)),
            $sealedAt,
        );
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function decodeCanonical(string $value): ?string
    {
        if (strlen($value) !== 86 || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
            return null;
        }
        $decoded = base64_decode(strtr($value, '-_', '+/').'==', true);
        if ($decoded === false
            || strlen($decoded) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES
            || self::encode($decoded) !== $value) {
            return null;
        }

        return $decoded;
    }
}
