<?php

declare(strict_types=1);

namespace App\Events\LegalArchive;

final readonly class LegalDocumentOutboxPublished
{
    public function __construct(
        public string $messageId,
        public int $organizationId,
        public string $aggregateType,
        public string $aggregateId,
        public string $event,
        public array $payload,
    ) {}
}
