<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Profiles;

final readonly class LegalDocumentProfile
{
    /**
     * @param  array<string, array<string, mixed>>  $schema
     * @param  list<string>  $requiredFileRoles
     * @param  list<string>  $requiredFields
     */
    public function __construct(
        public string $code,
        public string $baseCode,
        public string $label,
        public string $category,
        public array $schema,
        public array $requiredFileRoles,
        public array $requiredFields,
        public bool $requiresSignature,
        public ?string $workflowTemplateId,
        public ?string $retentionPolicy,
        public string $confidentialityLevel,
        public bool $isActive,
        public int $lockVersion,
    ) {}
}
