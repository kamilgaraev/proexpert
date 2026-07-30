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
        string $applicationKey,
        private string $keyId = 'application-v1',
    ) {
        if (! function_exists('sodium_crypto_sign_seed_keypair')) {
            throw new InvalidArgumentException('report_snapshot_sealer_unavailable');
        }
        $seed = hash('sha256', $applicationKey, true);
        $this->secretKey = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($seed));
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
}
