<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Execution;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportSnapshotSealVerificationInput;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotSeal;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Security\TrustedReportSnapshotSealVerifier;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class TrustedReportSnapshotSealVerifierTest extends TestCase
{
    public function test_verifies_detached_signature_over_exact_framed_bytes(): void
    {
        $keyPair = sodium_crypto_sign_keypair();
        $public = sodium_crypto_sign_publickey($keyPair);
        $secret = sodium_crypto_sign_secretkey($keyPair);
        $input = $this->input($secret);

        self::expectNotToPerformAssertions();
        (new TrustedReportSnapshotSealVerifier([
            'seal-key-1' => ['public_key' => $this->base64Url($public), 'revoked' => false],
        ]))->assertTrusted($input);
    }

    public function test_signed_bytes_use_exact_domain_hash_and_closed_canonical_object(): void
    {
        $hash = new Sha256Hash(str_repeat('d', 64));
        $seal = new ReportSnapshotSeal(
            'seal-key-1',
            'ed25519-sha256',
            $hash,
            $this->base64Url(str_repeat("\0", 64)),
            new DateTimeImmutable('2026-07-29T08:00:00.000000+00:00'),
        );
        $input = new ReportSnapshotSealVerificationInput(
            $seal,
            'snapshot-1',
            'materialized',
            ReportSnapshotClassification::OFFICIAL,
            new DateTimeImmutable('2026-07-29T10:00:00.000000+03:00'),
            $hash,
        );
        $expectedObject = '{"generated_at":"2026-07-29T07:00:00.000000Z","seal_algorithm":"ed25519-sha256","seal_key_id":"seal-key-1","sealed_at":"2026-07-29T08:00:00.000000Z","sealed_payload_hash":"'.str_repeat('d', 64).'","snapshot_classification":"official","snapshot_id":"snapshot-1","snapshot_kind":"materialized"}';

        self::assertSame(
            "most-report-snapshot-seal-v1\0".str_repeat("\xdd", 32)."\0".$expectedObject,
            $input->signedBytes(),
        );
    }

    #[DataProvider('invalidVerificationInputProvider')]
    public function test_verification_input_rejects_each_invalid_identity_boundary(array $changes): void
    {
        $hash = new Sha256Hash(str_repeat('d', 64));
        $seal = new ReportSnapshotSeal(
            'seal-key-1',
            'ed25519-sha256',
            new Sha256Hash($changes['sealed_hash'] ?? $hash->value),
            $this->base64Url(str_repeat("\0", 64)),
            new DateTimeImmutable($changes['sealed_at'] ?? '2026-07-29T08:00:00.000000Z'),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_snapshot_seal_verification_input_invalid');
        new ReportSnapshotSealVerificationInput(
            $seal,
            $changes['snapshot_id'] ?? 'snapshot-1',
            $changes['snapshot_kind'] ?? 'materialized',
            $changes['classification'] ?? ReportSnapshotClassification::OFFICIAL,
            new DateTimeImmutable($changes['generated_at'] ?? '2026-07-29T07:00:00.000000Z'),
            new Sha256Hash($changes['calculated_hash'] ?? $hash->value),
        );
    }

    public static function invalidVerificationInputProvider(): array
    {
        return [
            'invalid snapshot id' => [['snapshot_id' => ' snapshot-1']],
            'invalid snapshot kind' => [['snapshot_kind' => 'Materialized']],
            'classification drift' => [['classification' => ReportSnapshotClassification::OPERATIONAL]],
            'seal before generation' => [[
                'sealed_at' => '2026-07-29T06:59:59.999999Z',
            ]],
            'payload hash drift' => [[
                'sealed_hash' => str_repeat('e', 64),
            ]],
        ];
    }

    #[DataProvider('invalidKeyMapProvider')]
    public function test_constructor_rejects_non_closed_or_untrusted_key_map(array $map): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('trusted_report_snapshot_seal_keys_invalid');
        new TrustedReportSnapshotSealVerifier($map);
    }

    public static function invalidKeyMapProvider(): array
    {
        $valid = str_repeat('A', 43);

        return [
            'empty' => [[]],
            'list-root' => [[['public_key' => $valid, 'revoked' => false]]],
            'extra' => [['seal-key-1' => ['public_key' => $valid, 'revoked' => false, 'private_key' => 'x']]],
            'missing' => [['seal-key-1' => ['public_key' => $valid]]],
            'numeric-root-key' => [[1 => ['public_key' => $valid, 'revoked' => false]]],
            'short-key-id' => [['x' => ['public_key' => $valid, 'revoked' => false]]],
            'uppercase-key-id' => [['Seal-key-1' => ['public_key' => $valid, 'revoked' => false]]],
            'scalar-entry' => [['seal-key-1' => 'key']],
            'list-entry' => [['seal-key-1' => [$valid, false]]],
            'public-key-not-string' => [['seal-key-1' => ['public_key' => 1, 'revoked' => false]]],
            'revoked-not-boolean' => [['seal-key-1' => ['public_key' => $valid, 'revoked' => 0]]],
            'short-public-key' => [['seal-key-1' => ['public_key' => str_repeat('A', 42), 'revoked' => false]]],
            'padded-public-key' => [['seal-key-1' => ['public_key' => str_repeat('A', 43).'=', 'revoked' => false]]],
            'invalid-base64url-public-key' => [['seal-key-1' => ['public_key' => str_repeat('A', 42).'$', 'revoked' => false]]],
            'noncanonical-public-key' => [['seal-key-1' => ['public_key' => str_repeat('A', 42).'B', 'revoked' => false]]],
        ];
    }

    #[DataProvider('untrustedSealMutationProvider')]
    public function test_every_signed_field_mutation_maps_only_to_safe_contract_error(string $mutation): void
    {
        $keyPair = sodium_crypto_sign_keypair();
        $public = sodium_crypto_sign_publickey($keyPair);
        $secret = sodium_crypto_sign_secretkey($keyPair);
        $input = $this->mutateSignedInput($this->input($secret), $mutation);
        $verifier = new TrustedReportSnapshotSealVerifier([
            'seal-key-1' => ['public_key' => $this->base64Url($public), 'revoked' => false],
        ]);

        $this->assertSafeUnsealed(static fn () => $verifier->assertTrusted($input));
    }

    public static function untrustedSealMutationProvider(): array
    {
        return [
            'snapshot id' => ['snapshot_id'],
            'snapshot kind' => ['snapshot_kind'],
            'generated instant' => ['generated_at'],
            'seal key id' => ['seal_key_id'],
            'sealed payload and calculated hash' => ['hash'],
            'seal instant' => ['sealed_at'],
            'signature' => ['signature'],
            'algorithm drift' => ['algorithm'],
            'classification drift' => ['classification'],
        ];
    }

    public function test_unknown_key_maps_only_to_safe_contract_error(): void
    {
        $keyPair = sodium_crypto_sign_keypair();
        $input = $this->mutateSignedInput(
            $this->input(sodium_crypto_sign_secretkey($keyPair)),
            'seal_key_id',
        );
        $verifier = new TrustedReportSnapshotSealVerifier([
            'seal-key-1' => [
                'public_key' => $this->base64Url(sodium_crypto_sign_publickey($keyPair)),
                'revoked' => false,
            ],
        ]);

        $this->assertSafeUnsealed(static fn () => $verifier->assertTrusted($input));
    }

    public function test_revoked_key_maps_only_to_safe_contract_error(): void
    {
        $keyPair = sodium_crypto_sign_keypair();
        $input = $this->input(sodium_crypto_sign_secretkey($keyPair));
        $verifier = new TrustedReportSnapshotSealVerifier([
            'seal-key-1' => [
                'public_key' => $this->base64Url(sodium_crypto_sign_publickey($keyPair)),
                'revoked' => true,
            ],
        ]);

        $this->assertSafeUnsealed(static fn () => $verifier->assertTrusted($input));
    }

    #[DataProvider('malformedSignatureProvider')]
    public function test_malformed_or_padded_signature_maps_only_to_safe_contract_error(string $signature): void
    {
        $keyPair = sodium_crypto_sign_keypair();
        $input = $this->input(sodium_crypto_sign_secretkey($keyPair));
        $forgedSeal = $this->forgeReadonly($input->seal, ['signature' => $signature]);
        $forgedInput = $this->forgeReadonly($input, ['seal' => $forgedSeal]);
        $verifier = new TrustedReportSnapshotSealVerifier([
            'seal-key-1' => [
                'public_key' => $this->base64Url(sodium_crypto_sign_publickey($keyPair)),
                'revoked' => false,
            ],
        ]);

        $this->assertSafeUnsealed(static fn () => $verifier->assertTrusted($forgedInput));
    }

    public static function malformedSignatureProvider(): array
    {
        return [
            'short' => [str_repeat('A', 85)],
            'padded' => [str_repeat('A', 86).'=='],
            'invalid alphabet' => [str_repeat('A', 85).'$'],
            'noncanonical final bits' => [str_repeat('A', 85).'B'],
        ];
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_unavailable_sodium_maps_only_to_safe_contract_error(): void
    {
        eval('namespace App\\BusinessModules\\Core\\Reporting\\Infrastructure\\Security; function function_exists(string $name): bool { return $name === "sodium_crypto_sign_verify_detached" ? false : \\function_exists($name); }');
        $keyPair = sodium_crypto_sign_keypair();
        $input = $this->input(sodium_crypto_sign_secretkey($keyPair));
        $verifier = new TrustedReportSnapshotSealVerifier([
            'seal-key-1' => [
                'public_key' => $this->base64Url(sodium_crypto_sign_publickey($keyPair)),
                'revoked' => false,
            ],
        ]);

        $this->assertSafeUnsealed(static fn () => $verifier->assertTrusted($input));
    }

    private function input(string $secret): ReportSnapshotSealVerificationInput
    {
        $hash = new Sha256Hash(str_repeat('d', 64));
        $unsigned = new ReportSnapshotSeal(
            'seal-key-1',
            'ed25519-sha256',
            $hash,
            $this->base64Url(str_repeat("\0", 64)),
            new DateTimeImmutable('2026-07-29T08:00:00.000000Z'),
        );
        $prototype = new ReportSnapshotSealVerificationInput(
            $unsigned,
            'snapshot-1',
            'materialized',
            ReportSnapshotClassification::OFFICIAL,
            new DateTimeImmutable('2026-07-29T07:00:00.000000Z'),
            $hash,
        );
        $seal = new ReportSnapshotSeal(
            $unsigned->keyId,
            $unsigned->algorithm,
            $unsigned->sealedPayloadHash,
            $this->base64Url(sodium_crypto_sign_detached($prototype->signedBytes(), $secret)),
            $unsigned->sealedAt,
        );

        return new ReportSnapshotSealVerificationInput(
            $seal,
            'snapshot-1',
            'materialized',
            ReportSnapshotClassification::OFFICIAL,
            new DateTimeImmutable('2026-07-29T07:00:00.000000Z'),
            $hash,
        );
    }

    private function mutateSignedInput(ReportSnapshotSealVerificationInput $input, string $mutation): ReportSnapshotSealVerificationInput
    {
        $sealChanges = [];
        $inputChanges = [];
        if ($mutation === 'snapshot_id') {
            $inputChanges['snapshotId'] = 'snapshot-2';
        } elseif ($mutation === 'snapshot_kind') {
            $inputChanges['snapshotKind'] = 'replica';
        } elseif ($mutation === 'generated_at') {
            $inputChanges['generatedAt'] = new DateTimeImmutable('2026-07-29T07:00:00.000001Z');
        } elseif ($mutation === 'seal_key_id') {
            $sealChanges['keyId'] = 'seal-key-2';
        } elseif ($mutation === 'hash') {
            $changedHash = new Sha256Hash(str_repeat('e', 64));
            $sealChanges['sealedPayloadHash'] = $changedHash;
            $inputChanges['calculatedSourceHash'] = $changedHash;
        } elseif ($mutation === 'sealed_at') {
            $sealChanges['sealedAt'] = new DateTimeImmutable('2026-07-29T08:00:00.000001Z');
        } elseif ($mutation === 'signature') {
            $sealChanges['signature'] = ($input->seal->signature[0] === 'A' ? 'B' : 'A').substr($input->seal->signature, 1);
        } elseif ($mutation === 'algorithm') {
            $sealChanges['algorithm'] = 'ed25519';
        } elseif ($mutation === 'classification') {
            $inputChanges['snapshotClassification'] = ReportSnapshotClassification::OPERATIONAL;
        }

        if ($sealChanges !== []) {
            $inputChanges['seal'] = $this->forgeReadonly($input->seal, $sealChanges);
        }

        return $this->forgeReadonly($input, $inputChanges);
    }

    private function forgeReadonly(object $source, array $changes): object
    {
        $reflection = new \ReflectionClass($source);
        $forged = $reflection->newInstanceWithoutConstructor();
        foreach ($reflection->getProperties() as $property) {
            $property->setValue(
                $forged,
                array_key_exists($property->getName(), $changes)
                    ? $changes[$property->getName()]
                    : $property->getValue($source),
            );
        }

        return $forged;
    }

    private function assertSafeUnsealed(callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected safe official-snapshot-unsealed error.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_OFFICIAL_SNAPSHOT_UNSEALED, $exception->errorCode);
            self::assertSame(ReportErrorCode::REPORT_OFFICIAL_SNAPSHOT_UNSEALED->value, $exception->getMessage());
            self::assertSame([], $exception->safeFields);
        }
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
