<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

final readonly class AssistantDomainDefinition
{
    public function __construct(
        public string $domain,
        public string $module,
        public string $entityType,
        public array $permissions,
        public array $fields,
        public array $schemas,
        public array $operations,
        public string $navigation,
        public string $sourceType = '',
        public array $entityTypes = [],
        public array $fieldPermissions = [],
        public array $entityPermissions = []
    ) {
    }

    public function schema(string $operation): ?array
    {
        return $this->schemas[$operation] ?? null;
    }
}
