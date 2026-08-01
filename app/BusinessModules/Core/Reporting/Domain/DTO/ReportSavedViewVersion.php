<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ReportSavedViewVersion
{
    public function __construct(
        public string $id,
        public string $savedViewId,
        public int $organizationId,
        public int $ownerId,
        public int $revision,
        public ReportSavedViewVersionContent $content,
        public Sha256Hash $contentHash,
        public Sha256Hash $reportDefinitionHash,
        public DateTimeImmutable $createdAt,
    ) {
        if (preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D', $id) !== 1
            || preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D', $savedViewId) !== 1
            || $organizationId < 1
            || $ownerId < 1
            || $revision < 1) {
            throw new InvalidArgumentException('report_saved_view_version_invalid');
        }

        if (! hash_equals(hash('sha256', $content->canonicalBytes()), $contentHash->value)) {
            throw new InvalidArgumentException('report_saved_view_version_content_hash_mismatch');
        }
    }
}
