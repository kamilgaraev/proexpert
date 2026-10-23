<?php

declare(strict_types=1);

namespace Tests\Unit\Authorization;

use App\BusinessModules\Features\AIAssistant\AIAssistantModule;
use PHPUnit\Framework\TestCase;

final class AssistantEgressPermissionCatalogTest extends TestCase
{
    public function test_organization_permission_is_published_consistently_without_publishing_platform_authority(): void
    {
        $root = dirname(__DIR__, 3);
        $catalog = json_decode(file_get_contents($root.'/config/ModuleList/addons/ai-assistant.json'), true, flags: JSON_THROW_ON_ERROR);
        $permissions = (new AIAssistantModule)->getPermissions();

        self::assertContains('ai_assistant.egress.manage', $permissions);
        self::assertSame($permissions, $catalog['permissions']);
        self::assertNotContains('admin.ai_assistant.egress.manage', $permissions);

        $translations = require $root.'/lang/ru/permissions.php';
        self::assertSame('Управление допуском данных помощника к провайдеру', $translations['values']['ai_assistant.egress.manage']);

        $owner = json_decode(file_get_contents($root.'/config/RoleDefinitions/lk/organization_owner.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertContains('*', $owner['module_permissions']['ai-assistant']);
    }
}
