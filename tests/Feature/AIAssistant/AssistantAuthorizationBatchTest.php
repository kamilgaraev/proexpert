<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Actions\Domains\DiscoverAssistantDomainCapabilitiesTool;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainCatalog;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainDefinition;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainReadService;
use App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceGuard;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantAuthorizationBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalogue_permission_queries_do_not_grow_with_repeated_protected_fields(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $access = app(AssistantDataAccessPolicy::class);
        $authorization = app(AuthorizationService::class);
        $counts = [];
        foreach ([8, 96] as $size) {
            $fields = array_map(static fn (int $index): string => 'field_'.$index, range(1, $size));
            $definition = new AssistantDomainDefinition('projects', 'project-management', 'project', ['projects.view'],
                $fields, [], ['read'], '/projects/{id}', 'project', ['project'],
                array_fill_keys($fields, 'finance.view_project_budget'));
            $tool = new DiscoverAssistantDomainCapabilitiesTool(new AssistantDomainCatalog([$definition]), $access, $authorization);
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                $result = $tool->execute(['domain' => 'projects'], $fixture->owner, $fixture->organization);
                $counts[] = count(DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
                DB::flushQueryLog();
            }
            self::assertSame($size, $result['capabilities'][0]['field_count']);
        }
        self::assertLessThan(48, $counts[1], 'Repeated fields must not each reload roles and entitlements');
        self::assertLessThanOrEqual($counts[0] + 4, $counts[1], 'Permission SQL must remain bounded when protected fields grow twelvefold');
    }

    public function test_source_type_filtering_reads_entitlements_once_per_logical_operation(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $access = app(AssistantDataAccessPolicy::class);
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $types = $access->allowedSourceTypes($fixture->owner, $fixture->organization->id);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        self::assertContains('project', $types);
        $subscriptions = array_filter($queries, static fn (array $query): bool => str_contains($query['query'], 'organization_package_subscriptions'));
        self::assertLessThan(4, count($subscriptions), 'Source filtering must share one current entitlement read');
        self::assertLessThan(120, count($queries), 'Source filtering must not reload role and domain gates per registered entity');
        \App\Models\Module::query()->where('slug', 'project-management')->update(['is_active' => false]);
        self::assertNotContains('project', $access->allowedSourceTypes($fixture->owner, $fixture->organization->id));
    }

    public function test_catalogue_and_tool_boundaries_observe_current_role_and_package_revocation(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $tool = app(DiscoverAssistantDomainCapabilitiesTool::class);
        $checker = app(AIPermissionChecker::class);
        self::assertNotEmpty($tool->execute([], $fixture->member, $fixture->organization)['capabilities']);
        self::assertTrue($checker->canExecuteTool($fixture->member, 'assistant_domain_discover_capabilities'));
        $fixture->memberRole->update(['is_active' => false]);
        self::assertFalse($checker->canExecuteTool($fixture->member, 'assistant_domain_discover_capabilities'));
        $fixture->memberRole->update(['is_active' => true]);
        self::assertTrue($checker->canUseAssistant($fixture->member, $fixture->organization->id));
        $fixture->subscription->update(['status' => 'expired']);
        self::assertFalse($checker->canUseAssistant($fixture->member, $fixture->organization->id));
        self::assertFalse($checker->canExecuteTool($fixture->member, 'assistant_domain_discover_capabilities'));
    }

    public function test_fresh_nested_check_invalidates_outer_allow_after_role_revocation(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $access = app(AssistantDataAccessPolicy::class);
        $actor = $fixture->member;
        $organizationId = $fixture->organization->id;
        $access->withCurrentChecks($actor, $organizationId, function () use ($access, $fixture, $actor, $organizationId): void {
            self::assertTrue($access->canReadDomain($actor, $organizationId, 'assistant'));
            $fixture->memberAssignment->update(['is_active' => false]);
            self::assertFalse($access->withCurrentChecks($actor, $organizationId,
                fn (): bool => $access->canReadDomain($actor, $organizationId, 'assistant'), true));
            self::assertFalse($access->canReadDomain($actor, $organizationId, 'assistant'));
        });
        self::assertFalse($access->canReadDomain($actor, $organizationId, 'assistant'));
    }

    public function test_nested_actors_and_exception_cleanup_never_leak_role_or_organization_decisions(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $access = app(AssistantDataAccessPolicy::class);
        $organizationId = $fixture->organization->id;
        try {
            $access->withCurrentChecks($fixture->owner, $organizationId, function () use ($access, $fixture, $organizationId): void {
                self::assertTrue($access->canReadDomain($fixture->owner, $organizationId, 'projects'));
                self::assertTrue($access->withCurrentChecks($fixture->owner, $organizationId,
                    fn (): bool => $access->canReadDomain($fixture->owner, $organizationId, 'projects')));
                self::assertFalse($access->withCurrentChecks($fixture->member, $organizationId,
                    fn (): bool => $access->canReadDomain($fixture->member, $organizationId, 'projects')));
                self::assertTrue($access->withCurrentChecks($fixture->foreignOwner, $fixture->foreignOrganization->id,
                    fn (): bool => $access->canReadDomain($fixture->foreignOwner, $fixture->foreignOrganization->id, 'knowledge')));
                self::assertFalse($access->withCurrentChecks($fixture->foreignOwner, $fixture->foreignOrganization->id,
                    fn (): bool => $access->canReadDomain($fixture->foreignOwner, $organizationId, 'projects')));
                self::assertTrue($access->canReadDomain($fixture->owner, $organizationId, 'projects'));
                $access->withCurrentChecks($fixture->owner, $organizationId, static function (): void {
                    throw new RuntimeException('batch_cleanup_probe');
                }, true);
            });
            self::fail('Expected exception');
        } catch (RuntimeException $exception) {
            self::assertSame('batch_cleanup_probe', $exception->getMessage());
        }
        $fixture->ownerAssignment->update(['is_active' => false]);
        self::assertFalse($access->canReadDomain($fixture->owner, $organizationId, 'projects'));
        self::assertFalse($access->withCurrentChecks($fixture->owner, $organizationId,
            fn (): bool => $access->canReadDomain($fixture->owner, $organizationId, 'projects')));
        self::assertFalse($access->canReadDomain($fixture->member, $organizationId, 'projects'));
    }

    public function test_publication_rechecks_protected_fields_after_provider_wait_and_project_revocation(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $fixture->memberRole->update(['module_permissions' => [
            'ai-assistant' => ['ai_assistant.chat'], 'project-management' => ['projects.view'],
        ], 'system_permissions' => ['finance.view_project_budget']]);
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->organization->id, 'budget_amount' => '42.17']));
        $fixture->member->assignedProjects()->attach($project->id, ['is_active' => true, 'role' => 'member']);
        $reader = app(AssistantDomainReadService::class);
        $guard = app(AssistantSourceReferenceGuard::class);
        $receipt = $reader->execute('read', ['domain' => 'projects', 'id' => $project->id, 'fields' => ['name', 'budget_amount']],
            $fixture->member, $fixture->organization->id);
        self::assertSame('42.17', $receipt['results'][0]['fields']['budget_amount']);
        self::assertTrue($guard->fresh($fixture->member, $fixture->organization->id, $receipt['source_refs']));
        $provider = $this->createMock(\App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface::class);
        $provider->expects($this->once())->method('chat')->willReturnCallback(function () use ($fixture): array {
            $fixture->memberRole->update(['system_permissions' => []]);
            return ['content' => 'Бюджет 42.17'];
        });
        $provider->chat([], []);
        self::assertFalse($guard->fresh($fixture->member, $fixture->organization->id, $receipt['source_refs']));
        $name = $reader->execute('read', ['domain' => 'projects', 'id' => $project->id, 'fields' => ['name', 'budget_amount']],
            $fixture->member, $fixture->organization->id);
        self::assertArrayNotHasKey('budget_amount', $name['results'][0]['fields']);
        self::assertTrue($guard->fresh($fixture->member, $fixture->organization->id, $name['source_refs']));
        $fixture->member->assignedProjects()->updateExistingPivot($project->id, ['is_active' => false]);
        self::assertFalse($guard->fresh($fixture->member, $fixture->organization->id, $name['source_refs']));
    }
}
