<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Execution;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotSeal;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class ReportSnapshotSealVerificationInput
{
    public function __construct(
        public ReportSnapshotSeal $seal,
        public string $snapshotId,
        public string $snapshotKind,
        public ReportSnapshotClassification $snapshotClassification,
        public DateTimeImmutable $generatedAt,
        public Sha256Hash $calculatedSourceHash,
    ) {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D', $snapshotId) !== 1
            || preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/D', $snapshotKind) !== 1
            || $snapshotClassification !== ReportSnapshotClassification::OFFICIAL
            || $seal->sealedAt < $generatedAt
            || !hash_equals($seal->sealedPayloadHash->value, $calculatedSourceHash->value)) {
            throw new InvalidArgumentException('report_snapshot_seal_verification_input_invalid');
        }
    }

    public function signedBytes(): string
    {
        $rawHash = hex2bin($this->calculatedSourceHash->value);
        if ($rawHash === false || strlen($rawHash) !== 32) {
            throw new InvalidArgumentException('report_snapshot_seal_verification_input_invalid');
        }

        return "most-report-snapshot-seal-v1\0".$rawHash."\0".CanonicalJson::encode([
            'snapshot_id' => $this->snapshotId,
            'snapshot_kind' => $this->snapshotKind,
            'snapshot_classification' => $this->snapshotClassification->value,
            'generated_at' => $this->utc($this->generatedAt),
            'seal_key_id' => $this->seal->keyId,
            'seal_algorithm' => $this->seal->algorithm,
            'sealed_payload_hash' => $this->seal->sealedPayloadHash->value,
            'sealed_at' => $this->utc($this->seal->sealedAt),
        ]);
    }

    private function utc(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
