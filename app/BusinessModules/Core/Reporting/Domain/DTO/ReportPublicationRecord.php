<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\Enums\ReportPublicationStatus;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ReportPublicationRecord
{
    public function __construct(
        public ReportPublicationIdentity $identity,
        public ReportPublicationStatus $status,
        public ReportPublicationProof $proof,
        public array $candidateDocument,
        public DateTimeImmutable $publishedAt,
        public ?DateTimeImmutable $disabledAt,
        public ?string $disabledReason,
        public ?string $releaseArtifactBytes = null,
    ) {
        if (! hash_equals($identity->proofHash->value, $proof->digest()->value)
            || ($status === ReportPublicationStatus::PUBLISHED
                && ($disabledAt !== null || $disabledReason !== null))
            || ($status === ReportPublicationStatus::DISABLED
                && ($disabledAt === null || $disabledReason === null))
            || ($releaseArtifactBytes !== null && $releaseArtifactBytes === '')) {
            throw new InvalidArgumentException('report_publication_record_invalid');
        }
    }
}
