<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class ReportPublicationLock
{
    public function __construct(
        public string $code,
        public Sha256Hash $previousManifestHash,
        public Sha256Hash $candidateManifestHash,
        public Sha256Hash $publishedManifestHash,
        public Sha256Hash $definitionHash,
        public Sha256Hash $conformanceHash,
        public string $releaseSha,
        public DateTimeImmutable $publishedAt,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{2,63}$/D', $code) !== 1
            || preg_match('/^[a-f0-9]{40}$/D', $releaseSha) !== 1
            || $publishedAt->getOffset() !== 0
            || $publishedAt->format('u') !== '000000') {
            throw new InvalidArgumentException('report_publication_lock_invalid');
        }
    }

    public function digest(): Sha256Hash
    {
        return new Sha256Hash(hash('sha256', CanonicalJson::encode($this->canonicalPayload())));
    }

    public function canonicalPayload(): array
    {
        return [
            'artifact_id' => 'report_publication_lock',
            'candidate_manifest_hash' => $this->candidateManifestHash->value,
            'code' => $this->code,
            'conformance_hash' => $this->conformanceHash->value,
            'definition_hash' => $this->definitionHash->value,
            'previous_manifest_hash' => $this->previousManifestHash->value,
            'published_at' => $this->publishedAt
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z'),
            'published_manifest_hash' => $this->publishedManifestHash->value,
            'release_sha' => $this->releaseSha,
            'schema_version' => '1.0.0',
        ];
    }
}
