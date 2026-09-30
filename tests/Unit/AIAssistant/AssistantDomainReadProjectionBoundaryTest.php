<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainReadService;
use App\BusinessModules\Features\AIAssistant\Services\AssistantExtendedDomainRegistry;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class AssistantDomainReadProjectionBoundaryTest extends TestCase
{
    public function test_tender_file_read_never_selects_timestamp_missing_from_public_acl_projection(): void
    {
        $safe = AssistantExtendedDomainRegistry::values('safeSelectColumns')['tender_file'];
        $versions = AssistantExtendedDomainRegistry::values('versionColumns')['tender_file'];
        $physical = array_values(array_unique([...$safe, 'created_at', 'updated_at']));
        self::assertContains('updated_at', $physical);
        self::assertNotContains('updated_at', $safe);
        $selected = $this->columns('read', ['id', 'original_name', 'mime_type', 'size'], $physical, $safe, $versions);
        self::assertSame([], array_diff($selected, $safe));
        self::assertContains('id', $selected);
        self::assertContains('original_name', $selected);
        self::assertNotContains('updated_at', $selected);
        self::assertNotContains('created_at', $selected);
    }

    public function test_allowed_created_or_updated_version_is_kept_with_project_identity(): void
    {
        self::assertSame(['id', 'project_id', 'created_at', 'summary'], $this->columns('read', ['summary'],
            ['id', 'project_id', 'created_at', 'updated_at', 'summary'], ['id', 'summary', 'created_at'], ['updated_at', 'created_at']));
        self::assertSame(['id', 'updated_at', 'summary'], $this->columns('read', ['summary'],
            ['id', 'updated_at', 'summary'], ['id', 'summary', 'updated_at'], ['updated_at']));
        self::assertSame(['id', 'project_id'], $this->columns('navigation', ['summary'],
            ['id', 'project_id', 'updated_at', 'summary'], ['id', 'summary'], ['updated_at']));
    }

    private function columns(string $operation, array $fields, array $physical, array $safe, array $versions): array
    {
        return (new ReflectionMethod(AssistantDomainReadService::class, 'projectedColumns'))
            ->invoke(null, $operation, $fields, $physical, $safe, $versions, 'id');
    }
}
