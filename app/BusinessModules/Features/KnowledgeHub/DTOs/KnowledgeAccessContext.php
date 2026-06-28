<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\KnowledgeHub\DTOs;

use App\BusinessModules\Features\KnowledgeHub\Enums\KnowledgeSurface;

final readonly class KnowledgeAccessContext
{
    /**
     * @param list<string> $audiences
     * @param list<string> $permissionKeys
     * @param list<string> $moduleSlugs
     */
    public function __construct(
        public KnowledgeSurface $surface,
        public array $audiences,
        public array $permissionKeys,
        public array $moduleSlugs,
        public ?string $moduleSlug,
        public ?string $permissionKey,
        public ?string $contextKey,
        public ?int $userId,
        public ?int $organizationId,
    ) {
    }
}
