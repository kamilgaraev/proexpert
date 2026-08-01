<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationReleaseArtifact;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use InvalidArgumentException;

final readonly class Ed25519ReportPublicationReleaseArtifactSigner
{
    public function __construct(
        private string $issuer,
        private string $keyId,
        private string $secretKey,
    ) {
        if (strlen($this->secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new InvalidArgumentException('report_publication_release_signing_key_invalid');
        }
    }

    public function issue(array $provenance, array $subject, array $evidence): string
    {
        $unsigned = [
            'algorithm' => 'ed25519',
            'artifact_id' => 'most.report_publication.release',
            'evidence' => $evidence,
            'issuer' => $this->issuer,
            'key_id' => $this->keyId,
            'provenance' => $provenance,
            'schema_version' => '1.0.0',
            'subject' => $subject,
        ];
        $signature = sodium_crypto_sign_detached(CanonicalJson::encode($unsigned), $this->secretKey);
        $artifact = $unsigned + ['signature' => self::base64Url($signature)];

        return ReportPublicationReleaseArtifact::fromArray($artifact)->canonicalBytes();
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
