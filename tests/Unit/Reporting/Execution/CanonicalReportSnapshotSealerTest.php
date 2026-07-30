<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Execution;

use App\BusinessModules\Core\Reporting\Application\Execution\ReportSnapshotSealVerificationInput;
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
        $applicationKey = 'base64:'.base64_encode(str_repeat('s', 32));
        $seed = hash('sha256', $applicationKey, true);
        $keyPair = sodium_crypto_sign_seed_keypair($seed);
        $publicKey = sodium_crypto_sign_publickey($keyPair);
        $generatedAt = new DateTimeImmutable('2026-07-30T08:00:00Z');
        $sourceHash = new Sha256Hash(str_repeat('a', 64));
        $seal = (new CanonicalReportSnapshotSealer($applicationKey))->seal(
            '01J00000000000000000000000',
            'workforce_admission',
            $generatedAt,
            $sourceHash,
            new DateTimeImmutable('2026-07-30T08:01:00Z'),
        );
        $verifier = new TrustedReportSnapshotSealVerifier([
            'application-v1' => [
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
    }
}
