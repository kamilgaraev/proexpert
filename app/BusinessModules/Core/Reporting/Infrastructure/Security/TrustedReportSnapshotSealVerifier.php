<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Security;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportSnapshotSealVerifier;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportSnapshotSealVerificationInput;
use InvalidArgumentException;
use Throwable;

final readonly class TrustedReportSnapshotSealVerifier implements ReportSnapshotSealVerifier
{
    private array $trustedSealKeys;

    public function __construct(array $trustedSealKeys)
    {
        if ($trustedSealKeys === [] || array_is_list($trustedSealKeys)) {
            throw new InvalidArgumentException('trusted_report_snapshot_seal_keys_invalid');
        }

        $validated = [];
        foreach ($trustedSealKeys as $keyId => $entry) {
            $entryKeys = is_array($entry) ? array_keys($entry) : [];
            sort($entryKeys, SORT_STRING);
            if (!is_string($keyId)
                || preg_match('/^[a-z][a-z0-9_.:-]{2,127}$/D', $keyId) !== 1
                || !is_array($entry)
                || array_is_list($entry)
                || $entryKeys !== ['public_key', 'revoked']
                || !is_string($entry['public_key'])
                || !is_bool($entry['revoked'])) {
                throw new InvalidArgumentException('trusted_report_snapshot_seal_keys_invalid');
            }
            $publicKey = $this->decodeCanonical($entry['public_key'], 43, 32);
            if ($publicKey === null) {
                throw new InvalidArgumentException('trusted_report_snapshot_seal_keys_invalid');
            }
            $validated[$keyId] = ['public_key' => $publicKey, 'revoked' => $entry['revoked']];
        }

        $this->trustedSealKeys = $validated;
    }

    public function assertTrusted(ReportSnapshotSealVerificationInput $input): void
    {
        try {
            $key = $this->trustedSealKeys[$input->seal->keyId] ?? null;
            $signature = $this->decodeCanonical($input->seal->signature, 86, 64);
            if (!is_array($key)
                || $key['revoked'] !== false
                || $input->seal->algorithm !== 'ed25519-sha256'
                || $signature === null
                || !function_exists('sodium_crypto_sign_verify_detached')
                || !sodium_crypto_sign_verify_detached($signature, $input->signedBytes(), $key['public_key'])) {
                throw new InvalidArgumentException('seal_untrusted');
            }
        } catch (Throwable $exception) {
            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_OFFICIAL_SNAPSHOT_UNSEALED,
                previous: $exception,
            );
        }
    }

    private function decodeCanonical(string $value, int $encodedLength, int $decodedLength): ?string
    {
        if (strlen($value) !== $encodedLength || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
            return null;
        }
        $padding = str_repeat('=', (4 - strlen($value) % 4) % 4);
        $decoded = base64_decode(strtr($value, '-_', '+/').$padding, true);
        if ($decoded === false
            || strlen($decoded) !== $decodedLength
            || rtrim(strtr(base64_encode($decoded), '+/', '-_'), '=') !== $value) {
            return null;
        }

        return $decoded;
    }
}
