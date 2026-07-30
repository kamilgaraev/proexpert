<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use InvalidArgumentException;

final readonly class PublishedDefinitionRelease
{
    public function __construct(
        public PublishedReportDefinition $published,
        public ReportPublicationLock $lock,
        public string $publishedBytes,
        public Sha256Hash $publishedBytesHash,
    ) {
        if ($publishedBytes === ''
            || ! mb_check_encoding($publishedBytes, 'UTF-8')
            || ! hash_equals(hash('sha256', $publishedBytes), $publishedBytesHash->value)
            || ! hash_equals($publishedBytesHash->value, $lock->publishedManifestHash->value)
            || ! hash_equals($published->code, $lock->code)
            || ! hash_equals($published->definitionHash->value, $lock->definitionHash->value)) {
            throw new InvalidArgumentException('published_definition_release_invalid');
        }
    }
}
