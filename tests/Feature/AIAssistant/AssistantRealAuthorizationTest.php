<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\AIAssistantModule;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AssistantAccessContextResolver;
use App\BusinessModules\Features\AIAssistant\Services\AssistantMemoryService;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\ConversationManager;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Module;
use App\Models\Project;
use App\Services\Credits\AICreditService;
use App\Services\Entitlements\OrganizationEntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantRealAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_owner_admin_and_member_permissions_match_registered_module(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $organizationId = (int) $fixture->organization->id;
        $checker = app(AIPermissionChecker::class);
        $authorization = app(AuthorizationService::class);
        $metadata = Module::query()->where('slug', 'ai-assistant')->firstOrFail();
        $this->assertSame($metadata->permissions, (new AIAssistantModule)->getPermissions());
        $this->assertTrue(app(OrganizationEntitlementService::class)->hasModuleAccess($organizationId, 'ai-assistant'));
        foreach ([$fixture->owner, $fixture->administrator, $fixture->member] as $actor) {
            $this->assertTrue($checker->canUseAssistant($actor, $organizationId));
            foreach (['ai_assistant.chat', 'ai-assistant.chat', 'ai_assistant.reports.generate', 'ai_assistant.analytics.view'] as $permission) {
                $this->assertTrue($authorization->canCurrent($actor, $permission, ['organization_id' => $organizationId]), $permission);
            }
        }
        $this->assertTrue($checker->canManageOrganizationConversations($fixture->owner, $organizationId));
        $this->assertFalse($checker->canManageOrganizationConversations($fixture->member, $organizationId));
        $this->assertTrue($authorization->canCurrent($fixture->administrator, 'admin.ai_assistant.rag.manage', ['organization_id' => $organizationId]));
        $this->assertTrue(app(AICreditService::class)->canPurchase($fixture->organization, $fixture->owner));
        $this->assertFalse(app(AICreditService::class)->canPurchase($fixture->organization, $fixture->member));
        $this->assertFalse($checker->canUseAssistant($fixture->foreignOwner, $organizationId));
        $this->assertFalse($authorization->canCurrent($fixture->foreignOwner, 'ai_assistant.chat', ['organization_id' => $organizationId]));
        $this->assertTrue($checker->canExecuteTool($fixture->owner, 'create_schedule_task'));
        $this->assertFalse($checker->canExecuteTool($fixture->member, 'create_schedule_task'));
        $this->assertFalse(app(AssistantAccessContextResolver::class)->resolve($fixture->owner, $organizationId)['is_read_only']);
        $this->assertTrue(app(AssistantAccessContextResolver::class)->resolve($fixture->member, $organizationId)['is_read_only']);
    }

    public function test_chat_member_can_create_memory_and_share_own_conversation_without_admin_permission(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $organizationId = (int) $fixture->organization->id;
        $conversations = app(ConversationManager::class);
        $conversation = $conversations->createConversation($organizationId, $fixture->member, 'План работ');
        $participants = $conversations->updateParticipants($conversation, $fixture->member, $organizationId, [
            ['user_id' => $fixture->owner->id, 'role' => 'viewer'],
        ]);
        $this->assertCount(2, $participants);
        $this->assertNotNull($conversations->findAccessibleConversation($conversation->id, $fixture->owner, $organizationId));
        $this->assertNull($conversations->findAccessibleConversation($conversation->id, $fixture->foreignOwner, $organizationId));
        $memory = app(AssistantMemoryService::class)->create($fixture->member, $organizationId, [
            'content' => 'Показывать сначала текущие задачи', 'confirmed' => true, 'source_refs' => [],
            'conversation_id' => $conversation->id,
        ]);
        $this->assertSame((int) $fixture->member->id, (int) $memory->user_id);
        $this->assertCount(1, app(AssistantMemoryService::class)->list($fixture->member, $organizationId));
        $this->expectException(RuntimeException::class);
        $conversations->updateParticipants($conversation, $fixture->member, $organizationId, [
            ['user_id' => $fixture->foreignOwner->id, 'role' => 'viewer'],
        ]);
    }

    public function test_warmed_real_permissions_do_not_survive_current_role_or_package_revocation(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $organizationId = (int) $fixture->organization->id;
        $authorization = app(AuthorizationService::class);
        $checker = app(AIPermissionChecker::class);
        $context = ['organization_id' => $organizationId];
        $this->assertTrue($authorization->can($fixture->member, 'ai_assistant.chat', $context));
        $this->assertTrue($checker->canUseAssistant($fixture->member, $organizationId));
        $fixture->memberRole->update(['module_permissions' => ['ai-assistant' => ['ai_assistant.usage.view']]]);
        $this->assertFalse($authorization->canCurrent($fixture->member, 'ai_assistant.chat', $context));
        $this->assertFalse($checker->canUseAssistant($fixture->member, $organizationId));
        $fixture->memberRole->update(['module_permissions' => ['ai-assistant' => ['ai_assistant.chat']]]);
        $this->assertTrue($checker->canUseAssistant($fixture->member, $organizationId));
        $fixture->memberAssignment->update(['is_active' => false]);
        $this->assertFalse($checker->canUseAssistant($fixture->member, $organizationId));
        $fixture->memberAssignment->update(['is_active' => true]);
        $this->assertTrue($checker->canUseAssistant($fixture->member, $organizationId));
        $fixture->subscription->update(['current_period_end_at' => now()->subSecond()]);
        $this->assertFalse($checker->canUseAssistant($fixture->member, $organizationId));
        $this->assertFalse($checker->canUseAssistant($fixture->owner, $organizationId));
    }

    public function test_foreign_role_and_revoked_membership_cannot_supply_current_context_permissions(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $organizationId = (int) $fixture->organization->id;
        $foreignContext = AuthorizationContext::getOrganizationContext($fixture->foreignOrganization->id);
        UserRoleAssignment::query()->create([
            'user_id' => $fixture->member->id, 'role_slug' => 'organization_owner', 'role_type' => 'system',
            'context_id' => $foreignContext->id, 'is_active' => true, 'assigned_by' => $fixture->foreignOwner->id,
        ]);
        $access = app(AssistantAccessContextResolver::class)->resolve($fixture->member, $organizationId);
        $this->assertTrue($access['is_read_only']);
        $this->assertFalse(app(AuthorizationService::class)->canCurrent($fixture->member, 'billing.manage', ['organization_id' => $organizationId]));
        $this->assertTrue(app(AIPermissionChecker::class)->canUseAssistant($fixture->member, $organizationId));
        $fixture->organization->users()->updateExistingPivot($fixture->member->id, ['is_active' => false]);
        $this->assertFalse(app(AIPermissionChecker::class)->canUseAssistant($fixture->member, $organizationId));
    }

    public function test_credit_purchase_rechecks_real_billing_role_active_membership_and_current_actor(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $organizationId = (int) $fixture->organization->id;
        $credits = app(AICreditService::class);
        $this->assertTrue(app(AuthorizationService::class)->can($fixture->owner, 'billing.manage', ['organization_id' => $organizationId]));
        $this->assertTrue($credits->canPurchase($fixture->organization, $fixture->owner));
        $fixture->ownerAssignment->update(['is_active' => false]);
        $this->assertFalse($credits->canPurchase($fixture->organization, $fixture->owner));
        $fixture->ownerAssignment->update(['is_active' => true]);
        $this->assertTrue($credits->canPurchase($fixture->organization, $fixture->owner));
        $fixture->organization->users()->updateExistingPivot($fixture->owner->id, ['is_active' => false]);
        $this->assertFalse($credits->canPurchase($fixture->organization, $fixture->owner));
        $fixture->organization->users()->updateExistingPivot($fixture->owner->id, ['is_active' => true]);
        $fixture->owner->update(['is_active' => false]);
        $this->assertFalse($credits->canPurchase($fixture->organization, $fixture->owner));
        $fixture->owner->update(['is_active' => true, 'current_organization_id' => $fixture->foreignOrganization->id]);
        $this->assertFalse($credits->canPurchase($fixture->organization, $fixture->owner));
        $this->assertFalse($credits->canPurchase($fixture->organization, $fixture->foreignOwner));
    }

    public function test_real_domain_role_requires_current_project_assignment_and_read_permission(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $organizationId = (int) $fixture->organization->id;
        $fixture->memberRole->update(['module_permissions' => [
            'ai-assistant' => ['ai_assistant.chat'], 'project-management' => ['projects.view'],
        ]]);
        $visible = Project::factory()->create(['organization_id' => $organizationId, 'is_archived' => false]);
        $private = Project::factory()->create(['organization_id' => $organizationId, 'is_archived' => false]);
        $fixture->member->assignedProjects()->attach($visible->id, ['role' => 'member', 'is_active' => true]);
        $policy = app(AssistantDataAccessPolicy::class);
        $this->assertTrue($policy->canReadEntity($fixture->member, $organizationId, 'project', $visible->id));
        $this->assertFalse($policy->canReadEntity($fixture->member, $organizationId, 'project', $private->id));
        $this->assertTrue($policy->canReadEntity($fixture->owner, $organizationId, 'project', $private->id));
        $this->assertTrue(app(AIPermissionChecker::class)->canExecuteTool($fixture->member, 'search_projects'));
        $fixture->member->assignedProjects()->updateExistingPivot($visible->id, ['is_active' => false]);
        $this->assertFalse($policy->canReadEntity($fixture->member, $organizationId, 'project', $visible->id));
        $fixture->member->assignedProjects()->updateExistingPivot($visible->id, ['is_active' => true]);
        $fixture->memberRole->update(['module_permissions' => ['ai-assistant' => ['ai_assistant.chat']]]);
        $this->assertFalse($policy->canReadEntity($fixture->member, $organizationId, 'project', $visible->id));
        $this->assertFalse(app(AIPermissionChecker::class)->canExecuteTool($fixture->member, 'search_projects'));
    }
}
