<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportPublicationReleaseArtifactVerifier;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationReleaseArtifact;
use InvalidArgumentException;

final readonly class Ed25519ReportPublicationReleaseArtifactVerifier implements ReportPublicationReleaseArtifactVerifier
{
    public function __construct(private array $trustedAuthorities)
    {
        if ($trustedAuthorities === []) {
            throw new InvalidArgumentException('report_publication_release_authorities_invalid');
        }
    }

    public function verify(string $artifactBytes): ReportPublicationReleaseArtifact
    {
        try {
            $artifact = ReportPublicationReleaseArtifact::fromCanonicalBytes($artifactBytes);
            $payload = $artifact->payload();
            $authority = $this->trustedAuthorities[$payload['issuer']][$payload['key_id']] ?? null;
            if (! is_array($authority)
                || array_keys($authority) !== [
                    'environment',
                    'event_name',
                    'job',
                    'public_key_base64',
                    'ref',
                    'repository',
                    'workflow_ref',
                ]) {
                $this->invalid();
            }
            $publicKey = base64_decode((string) $authority['public_key_base64'], true);
            $signature = self::decodeBase64Url($payload['signature']);
            $provenance = $payload['provenance'];
            $subject = $payload['subject'];
            $evidence = $payload['evidence'];
            if (! is_string($publicKey)
                || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
                || ! sodium_crypto_sign_verify_detached($signature, $artifact->signedPayloadBytes(), $publicKey)
                || ! hash_equals((string) $authority['repository'], $provenance['repository'])
                || ! hash_equals((string) $authority['workflow_ref'], $provenance['workflow_ref'])
                || ! hash_equals((string) $authority['job'], $provenance['job'])
                || ! hash_equals((string) $authority['event_name'], $provenance['event_name'])
                || ! hash_equals((string) $authority['ref'], $provenance['ref'])
                || ! hash_equals((string) $authority['environment'], $provenance['environment'])
                || ! hash_equals($provenance['run_id'], $evidence['run_id'])
                || ! hash_equals($provenance['commit_sha'], $evidence['commit_sha'])
                || ! hash_equals(
                    'report-publication-'.$subject['code'].'-'.$subject['proof_sha256'],
                    $provenance['artifact_name'],
                )) {
                $this->invalid();
            }

            return $artifact;
        } catch (InvalidArgumentException $exception) {
            if ($exception->getMessage() === 'report_publication_release_artifact_untrusted') {
                throw $exception;
            }
            throw new InvalidArgumentException('report_publication_release_artifact_untrusted', 0, $exception);
        }
    }

    private static function decodeBase64Url(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/').'==', true);
        if (! is_string($decoded) || strlen($decoded) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new InvalidArgumentException('report_publication_release_artifact_untrusted');
        }

        return $decoded;
    }

    private function invalid(): never
    {
        throw new InvalidArgumentException('report_publication_release_artifact_untrusted');
    }
}
