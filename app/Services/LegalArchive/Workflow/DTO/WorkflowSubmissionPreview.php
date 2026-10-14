<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Workflow\DTO;

final readonly class WorkflowSubmissionPreview
{
    public function __construct(
        public int $documentId,
        public ?int $documentVersionId,
        public int $documentLockVersion,
        public array $template,
        public array $steps,
    ) {}
}
