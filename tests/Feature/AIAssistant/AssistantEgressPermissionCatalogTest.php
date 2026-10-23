<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantEgressDenied;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantEnrollmentService;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Domain\Authorization\Services\CustomRoleService;
use App\Models\Module;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantEgressPermissionCatalogTest extends TestCase
{
    private const PERMISSION = 'ai_assistant.egress.manage';

    private const PATH = '/api/v1/landing/user-management/custom-roles';

    public function test_deployed_catalog_migration_and_custom_role_api_preserve_organization_only_authority(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        config(['cache.default' => 'array']);
        $fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $this->actingAs($fixture->owner);
        $this->withoutMiddleware();
        $module = Module::query()->where('slug', 'ai-assistant')->sole();
        $original = array_values(array_filter($module->permissions, static fn (string $permission): bool => $permission !== self::PERMISSION));
        $module->update(['permissions' => $original]);
        $roleCount = OrganizationCustomRole::query()->count();
        $foreignModule = Module::query()->where('slug', '<>', 'ai-assistant')->firstOrFail();
        $foreignBefore = $foreignModule->getRawOriginal();

        $migration = require base_path('database/migrations/2026_10_23_180000_add_assistant_egress_permission_to_module.php');
        self::assertInstanceOf(Migration::class, $migration);
        $migration->up();
        self::assertSame([...$original, self::PERMISSION], $module->refresh()->permissions);
        $afterFirst = $module->getRawOriginal();
        $migration->up();
        self::assertSame($afterFirst, $module->refresh()->getRawOriginal());
        self::assertSame($foreignBefore, $foreignModule->refresh()->getRawOriginal());
        self::assertSame($roleCount, OrganizationCustomRole::query()->count());

        $available = $this->getJson(self::PATH.'/permissions/available')->assertOk()->json('data');
        self::assertSame('Управление допуском данных помощника к провайдеру', $available['module_permissions']['ai_assistant'][self::PERMISSION]);
        self::assertArrayNotHasKey('admin.ai_assistant.egress.manage', $available['module_permissions']['ai_assistant']);

        $created = $this->postJson(self::PATH, ['name' => 'Проверка допуска помощника', 'interface_access' => ['lk'],
            'module_permissions' => ['ai_assistant' => [self::PERMISSION]]])->assertCreated()->json('data');
        $role = OrganizationCustomRole::query()->findOrFail($created['id']);
        self::assertSame((int) $fixture->organization->id, (int) $role->organization_id);
        self::assertSame(['ai_assistant' => [self::PERMISSION]], $role->module_permissions);
        self::assertNotContains('admin.ai_assistant.egress.manage', $role->system_permissions);

        $service = app(CustomRoleService::class);
        $authorization = app(AuthorizationService::class);
        $context = ['organization_id' => $fixture->organization->id];
        self::assertFalse($authorization->forCurrentChecks(true)->can($fixture->member, self::PERMISSION, $context));
        $service->assignRoleToUser($role, $fixture->member, $fixture->ownerAssignment->context, $fixture->owner);
        self::assertTrue($authorization->forCurrentChecks(true)->can($fixture->member, self::PERMISSION, $context));
        self::assertFalse($authorization->forCurrentChecks(true)->can($fixture->member, 'admin.ai_assistant.egress.manage', ['context_type' => 'system']));
        $this->assertThrows(fn () => app(OrdinaryAssistantEnrollmentService::class)->assertPlatform($fixture->member), AssistantEgressDenied::class);
        self::assertTrue($service->updateRole($role, ['module_permissions' => []], $fixture->owner, (int) $fixture->organization->id));
        self::assertSame([], $role->refresh()->module_permissions);
        self::assertFalse($authorization->forCurrentChecks(true)->can($fixture->member, self::PERMISSION, $context));
        self::assertTrue($service->updateRole($role, ['module_permissions' => ['ai_assistant' => [self::PERMISSION]]], $fixture->owner, (int) $fixture->organization->id));
        self::assertSame(['ai_assistant' => [self::PERMISSION]], $role->refresh()->module_permissions);
        self::assertTrue($authorization->forCurrentChecks(true)->can($fixture->member, self::PERMISSION, $context));

        $this->postJson(self::PATH, ['name' => 'Запрещённое системное право', 'interface_access' => ['lk'],
            'module_permissions' => ['ai_assistant' => ['admin.ai_assistant.egress.manage']]])->assertUnprocessable();
        $this->postJson(self::PATH, ['name' => 'Запрещённый системный grant', 'interface_access' => ['lk'],
            'system_permissions' => ['admin.ai_assistant.egress.manage']])->assertUnprocessable();
        self::assertTrue(app(AuthorizationService::class)->forCurrentChecks(true)->can($fixture->owner, self::PERMISSION,
            ['organization_id' => $fixture->organization->id]));

        $migration->down();
        self::assertSame($original, $module->refresh()->permissions);
        self::assertSame(['ai_assistant' => [self::PERMISSION]], $role->refresh()->module_permissions);
        self::assertSame($foreignBefore, $foreignModule->refresh()->getRawOriginal());
        Http::assertNothingSent();
    }
}
