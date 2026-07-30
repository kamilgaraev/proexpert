<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Execution;

use App\BusinessModules\Core\Reporting\Application\Execution\ReportSnapshotSealVerificationInput;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotSeal;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Security\CanonicalReportSnapshotSealer;
use App\BusinessModules\Core\Reporting\Infrastructure\Security\TrustedReportSnapshotSealVerifier;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class CanonicalReportSnapshotSealerTest extends TestCase
{
    public function test_sealer_produces_a_signature_accepted_by_the_canonical_verifier(): void
    {
        $keyPair = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($keyPair);
        $privateKey = sodium_crypto_sign_secretkey($keyPair);
        $generatedAt = new DateTimeImmutable('2026-07-30T08:00:00Z');
        $sourceHash = new Sha256Hash(str_repeat('a', 64));
        $seal = (new CanonicalReportSnapshotSealer(
            $this->base64Url($privateKey),
            'reports-2026-07',
        ))->seal(
            '01J00000000000000000000000',
            'workforce_admission',
            $generatedAt,
            $sourceHash,
            new DateTimeImmutable('2026-07-30T08:01:00Z'),
        );
        $verifier = new TrustedReportSnapshotSealVerifier([
            'reports-2026-07' => [
                'public_key' => rtrim(strtr(base64_encode($publicKey), '+/', '-_'), '='),
                'revoked' => false,
            ],
        ]);

        $verifier->assertTrusted(new ReportSnapshotSealVerificationInput(
            $seal,
            '01J00000000000000000000000',
            'workforce_admission',
            ReportSnapshotClassification::OFFICIAL,
            $generatedAt,
            $sourceHash,
        ));

        self::assertSame($sourceHash->value, $seal->sealedPayloadHash->value);
        self::assertSame('reports-2026-07', $seal->keyId);
    }

    public function test_rotated_historical_key_remains_verifiable_until_revoked(): void
    {
        $oldPair = sodium_crypto_sign_keypair();
        $newPair = sodium_crypto_sign_keypair();
        $generatedAt = new DateTimeImmutable('2026-07-30T08:00:00Z');
        $hash = new Sha256Hash(str_repeat('b', 64));
        $seal = (new CanonicalReportSnapshotSealer(
            $this->base64Url(sodium_crypto_sign_secretkey($oldPair)),
            'reports-2026-06',
        ))->seal('snapshot-rotation', 'quality_defect_flow', $generatedAt, $hash, $generatedAt);
        $input = new ReportSnapshotSealVerificationInput(
            $seal,
            'snapshot-rotation',
            'quality_defect_flow',
            ReportSnapshotClassification::OFFICIAL,
            $generatedAt,
            $hash,
        );
        $keys = [
            'reports-2026-06' => [
                'public_key' => $this->base64Url(sodium_crypto_sign_publickey($oldPair)),
                'revoked' => false,
            ],
            'reports-2026-07' => [
                'public_key' => $this->base64Url(sodium_crypto_sign_publickey($newPair)),
                'revoked' => false,
            ],
        ];
        (new TrustedReportSnapshotSealVerifier($keys))->assertTrusted($input);

        $this->expectException(\App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException::class);
        $keys['reports-2026-06']['revoked'] = true;
        (new TrustedReportSnapshotSealVerifier($keys))->assertTrusted($input);
    }

    public function test_unknown_key_and_forged_signature_fail_closed(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $generatedAt = new DateTimeImmutable('2026-07-30T08:00:00Z');
        $hash = new Sha256Hash(str_repeat('c', 64));
        $seal = (new CanonicalReportSnapshotSealer(
            $this->base64Url(sodium_crypto_sign_secretkey($pair)),
            'reports-2026-07',
        ))->seal('snapshot-negative', 'quality_defect_flow', $generatedAt, $hash, $generatedAt);
        $trusted = new TrustedReportSnapshotSealVerifier([
            'reports-2026-07' => [
                'public_key' => $this->base64Url(sodium_crypto_sign_publickey($pair)),
                'revoked' => false,
            ],
        ]);

        foreach ([
            new ReportSnapshotSeal(
                'unknown-reports-key',
                $seal->algorithm,
                $seal->sealedPayloadHash,
                $seal->signature,
                $seal->sealedAt,
            ),
            new ReportSnapshotSeal(
                $seal->keyId,
                $seal->algorithm,
                $seal->sealedPayloadHash,
                str_repeat('A', 86),
                $seal->sealedAt,
            ),
        ] as $invalidSeal) {
            try {
                $trusted->assertTrusted(new ReportSnapshotSealVerificationInput(
                    $invalidSeal,
                    'snapshot-negative',
                    'quality_defect_flow',
                    ReportSnapshotClassification::OFFICIAL,
                    $generatedAt,
                    $hash,
                ));
                self::fail('Expected an untrusted seal to fail closed.');
            } catch (\App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException $exception) {
                self::assertSame(
                    \App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode::REPORT_OFFICIAL_SNAPSHOT_UNSEALED,
                    $exception->errorCode,
                );
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidSignerConfiguration')]
    public function test_signer_fails_closed_for_invalid_configuration(string $privateKey, string $keyId): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('report_snapshot_signing_key_invalid');

        new CanonicalReportSnapshotSealer($privateKey, $keyId);
    }

    public static function invalidSignerConfiguration(): array
    {
        return [
            'missing private key' => ['', 'reports-2026-07'],
            'application key is not accepted' => ['base64:'.base64_encode(str_repeat('a', 32)), 'reports-2026-07'],
            'invalid key id' => [str_repeat('A', 86), 'APP_KEY'],
        ];
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
