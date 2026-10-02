<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Models\AssistantMemory;
use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainReadService;
use App\BusinessModules\Features\AIAssistant\Services\AssistantMemoryService;
use App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceGuard;
use App\BusinessModules\Features\AIAssistant\Services\ConversationManager;
use App\BusinessModules\Features\Procurement\Reporting\Award\DTO\ProcurementAwardCandidateEvidence;
use App\BusinessModules\Features\Procurement\Reporting\Award\DTO\ProcurementAwardManifest;
use App\BusinessModules\Features\Procurement\Reporting\Award\DTO\ProcurementAwardPolicyDefinition;
use App\BusinessModules\Features\Procurement\Reporting\Award\DTO\ProcurementAwardSelectionFact;
use App\BusinessModules\Features\Procurement\Reporting\Award\Enums\ProcurementAwardCompleteness;
use App\BusinessModules\Features\Procurement\Reporting\Award\Models\ProcurementAwardEvidenceEvent;
use App\BusinessModules\Features\Procurement\Reporting\Award\Services\EloquentProcurementAwardEvidenceStore;
use App\BusinessModules\Features\Procurement\Reporting\Award\Services\ProcurementAwardEvidenceRecorder;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Services\LaravelProcurementTransactionBoundary;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\Organization;
use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationPackageSubscription;
use App\Models\Project;
use App\Models\User;
use App\Services\Modules\PackageCatalogService;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\Support\Procurement\Reporting\Award\ProcurementAwardPostgresFixture;
use Tests\TestCase;

final class AssistantSourceReferencePrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_financial_then_name_only_proofs_both_persist_and_revoke_hides_history_summary_and_memory(): void
    {
        $fixture = $this->canonicalFixture();
        $permissions = ['ai-assistant' => ['ai_assistant.chat'], 'project-management' => ['projects.view']];
        $fixture->memberRole->update(['module_permissions' => $permissions, 'system_permissions' => ['finance.view_project_budget']]);
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->organization->id, 'name' => 'Проект доказательства', 'budget_amount' => '12345.67']));
        $fixture->member->assignedProjects()->attach($project->id, ['is_active' => true, 'role' => 'member']);
        $reader = app(AssistantDomainReadService::class);
        $money = $reader->execute('read', ['domain' => 'projects', 'id' => $project->id, 'fields' => ['budget_amount']], $fixture->member, $fixture->organization->id);
        $name = $reader->execute('read', ['domain' => 'projects', 'id' => $project->id, 'fields' => ['name']], $fixture->member, $fixture->organization->id);
        self::assertArrayHasKey('budget_amount', $money['results'][0]['fields']);
        self::assertContains('finance.view_project_budget', $money['source_refs'][0]['required_permissions']);
        self::assertNotContains('finance.view_project_budget', $name['source_refs'][0]['required_permissions']);
        $refs = $this->collect([$money, $name]);
        self::assertCount(2, $refs);
        [$conversation, $message, $memory] = $this->persist($fixture->member, $fixture->organization->id, $refs, 'Бюджет 12345.67 и название проекта');
        $manager = app(ConversationManager::class);
        self::assertCount(2, $message->refresh()->metadata['source_refs']);
        self::assertCount(2, $manager->getSummary($conversation, $fixture->member)->source_refs);
        self::assertTrue($manager->canReadMessage($message, $conversation, $fixture->member));
        self::assertCount(1, app(AssistantMemoryService::class)->forContext($fixture->member, $fixture->organization->id));

        $fixture->memberRole->update(['module_permissions' => $permissions, 'system_permissions' => []]);
        $guard = app(AssistantSourceReferenceGuard::class);
        self::assertTrue($guard->fresh($fixture->member, $fixture->organization->id, $name['source_refs']));
        self::assertFalse($guard->fresh($fixture->member, $fixture->organization->id, $money['source_refs']));
        self::assertFalse($manager->canReadMessage($message, $conversation, $fixture->member));
        self::assertCount(0, $manager->getHistory($conversation, actor: $fixture->member));
        self::assertSame([], $manager->getMessagesForContext($conversation, actor: $fixture->member));
        self::assertNull($manager->getSummary($conversation, $fixture->member));
        self::assertCount(0, app(AssistantMemoryService::class)->list($fixture->member, $fixture->organization->id));
        self::assertSame([], app(AssistantMemoryService::class)->forContext($fixture->member, $fixture->organization->id));
        self::assertDatabaseHas('ai_memories', ['id' => $memory->id]);
    }

    public function test_first_composite_child_parent_becomes_unavailable_while_last_stays_fresh_and_whole_old_answer_is_hidden(): void
    {
        $canonical = $this->canonicalFixture();
        $fixture = (new ProcurementAwardPostgresFixture(DB::connection()))->create('assistant-proof-'.Str::lower(Str::random(10)));
        $firstParty = $fixture['foreign']['supplier_party_id'];
        DB::table('supplier_proposals')->where('id', $fixture['first']['proposal_id'])->update(['supplier_party_id' => $firstParty]);
        $fixture['first']['supplier_party_id'] = $firstParty;
        $actor = $this->authorizeAwardOrganization($fixture);
        $candidates = array_map(fn (array $row): ProcurementAwardCandidateEvidence => $this->candidate($fixture, $row), [$fixture['first'], $fixture['second']]);
        $manifest = new ProcurementAwardManifest($candidates, ProcurementAwardCompleteness::COMPLETE,
            $fixture['first']['proposal_id'], $fixture['first']['proposal_version_id'], $fixture['first']['proposal_id'], $fixture['first']['proposal_version_id'], 1, 1, []);
        $fact = ProcurementAwardSelectionFact::create($fixture['organization_id'], $fixture['project_id'], $fixture['purchase_request_id'],
            $fixture['supplier_request']['supplier_request_id'], $fixture['supplier_request']['supplier_request_version_id'],
            $fixture['supplier_request']['supplier_request_version_hash'], $fixture['decision_id'], 'selected',
            new DateTimeImmutable('2026-08-01T10:00:00+00:00'), $fixture['user_id'], $manifest, ProcurementAwardPolicyDefinition::v1(), null);
        $event = DB::transaction(fn () => (new ProcurementAwardEvidenceRecorder(new EloquentProcurementAwardEvidenceStore, new LaravelProcurementTransactionBoundary))->captureSelection($fact));
        $parent = ProcurementAwardEvidenceEvent::query()->findOrFail($event->eventId);
        $receipt = app(AssistantDomainReadService::class)->execute('read', ['domain' => 'procurement_business',
            'entity_type' => 'procurement_award_evidence_event', 'id' => $parent->id, 'projection' => 'award_candidates'], $actor, $fixture['organization_id']);
        self::assertCount(2, $receipt['rows']);
        self::assertSame($firstParty, $receipt['rows'][0]['fields']['supplier_party_id']);
        self::assertNotSame($firstParty, $receipt['rows'][1]['fields']['supplier_party_id']);
        self::assertSame($parent->id, $receipt['source_refs'][0]['entity_id']);
        self::assertSame($parent->id, $receipt['source_refs'][1]['entity_id']);
        self::assertNotSame($receipt['source_refs'][0]['composite_key'], $receipt['source_refs'][1]['composite_key']);
        $refs = $this->collect([$receipt]);
        self::assertCount(2, $refs);
        [$conversation, $message] = $this->persist($actor, $fixture['organization_id'], $refs, $receipt['server_formatted_facts']);
        $guard = app(AssistantSourceReferenceGuard::class);
        self::assertTrue($guard->fresh($actor, $fixture['organization_id'], $refs));
        $this->travel(6)->minutes();
        self::assertFalse($guard->fresh($actor, $fixture['organization_id'], $refs));
        self::assertTrue(app(ConversationManager::class)->canReadMessage($message, $conversation, $actor));
        self::assertCount(1, app(ConversationManager::class)->getHistory($conversation, actor: $actor));
        self::assertSame([], app(ConversationManager::class)->getMessagesForContext($conversation, actor: $actor));
        self::assertSame([], app(AssistantMemoryService::class)->forContext($actor, $fixture['organization_id']));
        $this->travelBack();

        DB::table('supplier_parties')->where('id', $firstParty)->update(['organization_id' => $canonical->foreignOrganization->id]);
        self::assertFalse($guard->fresh($actor, $fixture['organization_id'], [$refs[0]]));
        self::assertTrue($guard->fresh($actor, $fixture['organization_id'], [$refs[1]]));
        self::assertFalse(app(ConversationManager::class)->canReadMessage($message, $conversation, $actor));
        self::assertCount(0, app(ConversationManager::class)->getHistory($conversation, actor: $actor));
        self::assertSame([], app(ConversationManager::class)->getMessagesForContext($conversation, actor: $actor));
        self::assertNull(app(ConversationManager::class)->getSummary($conversation, $actor)?->summary);
        self::assertSame([], app(AssistantMemoryService::class)->forContext($actor, $fixture['organization_id']));
        self::assertCount(0, app(AssistantMemoryService::class)->list($actor, $fixture['organization_id']));
    }

    private function canonicalFixture(): AssistantRealAuthorizationFixture
    {
        return AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug'));
    }

    private function collect(array $receipts): array
    {
        $service = (new ReflectionClass(AIAssistantService::class))->newInstanceWithoutConstructor();
        return (new ReflectionMethod(AIAssistantService::class, 'collectSourceRefs'))->invoke($service, [], $receipts);
    }

    private function persist(User $actor, int $organizationId, array $refs, string $text): array
    {
        $manager = app(ConversationManager::class);
        $conversation = $manager->createConversation($organizationId, $actor, 'Проверка ссылок');
        $message = $manager->addMessage($conversation, 'assistant', $text, metadata: ['validation_status' => 'verified', 'source_refs' => $refs]);
        $manager->saveSummary($conversation, $actor, ['validation_status' => 'verified', 'summary' => $text], $refs, (int) $conversation->context_version);
        $memory = AssistantMemory::query()->create(['organization_id' => $organizationId, 'user_id' => $actor->id,
            'created_by_user_id' => $actor->id, 'conversation_id' => $conversation->id, 'kind' => 'confirmed',
            'payload' => ['content' => $text], 'source_refs' => $refs, 'confirmed' => true, 'expires_at' => now()->addDay()]);
        return [$conversation, $message, $memory];
    }

    private function authorizeAwardOrganization(array $fixture): User
    {
        $actor = User::query()->findOrFail($fixture['user_id']);
        $actor->forceFill(['current_organization_id' => $fixture['organization_id'], 'is_active' => true])->save();
        $organization = Organization::query()->findOrFail($fixture['organization_id']);
        $organization->users()->attach($actor->id, ['is_owner' => true, 'is_active' => true, 'project_access_mode' => 'all_projects']);
        UserRoleAssignment::query()->create(['user_id' => $actor->id, 'role_slug' => 'organization_owner', 'role_type' => UserRoleAssignment::TYPE_SYSTEM,
            'context_id' => AuthorizationContext::getOrganizationContext($organization->id)->id, 'assigned_by' => $actor->id, 'is_active' => true]);
        $account = OrganizationCommercialAccount::query()->create(['organization_id' => $organization->id, 'responsible_user_id' => $actor->id,
            'status' => 'active', 'offer_type' => 'packages', 'quote_version' => 1, 'current_period_start_at' => now(),
            'current_period_end_at' => now()->addMonth(), 'auto_renew_enabled' => false]);
        foreach (app(PackageCatalogService::class)->allPackages() as $package) {
            OrganizationPackageSubscription::query()->create(['organization_id' => $organization->id, 'package_slug' => $package['slug'],
                'commercial_account_id' => $account->id, 'status' => 'active', 'access_source' => 'paid_package', 'price_paid' => 39900,
                'current_period_start_at' => now(), 'current_period_end_at' => now()->addMonth()]);
        }
        return $actor;
    }

    private function candidate(array $fixture, array $row): ProcurementAwardCandidateEvidence
    {
        return new ProcurementAwardCandidateEvidence(organizationId: $fixture['organization_id'], projectId: $fixture['project_id'],
            purchaseRequestId: $fixture['purchase_request_id'], supplierRequestId: $row['supplier_request_id'],
            supplierRequestVersionId: $row['supplier_request_version_id'], supplierRequestVersionHash: $row['supplier_request_version_hash'],
            proposalId: $row['proposal_id'], proposalVersionId: $row['proposal_version_id'], supplierPartyId: $row['supplier_party_id'],
            proposalStatus: 'accepted', proposalValidUntil: null, versionContentHash: $row['version_content_hash'],
            subtotalAmount: $row['total'], deliveryAmount: '0', vatAmount: '0', totalAmount: $row['total'], comparisonTotal: $row['total'],
            currency: 'RUB', vatMode: 'included', vatRate: '20', deliveryDueDate: '2026-08-10', leadTimeDays: 5,
            requestLineCoverage: [['supplier_request_line_id' => $row['supplier_request_line_id'], 'required_quantity' => '1',
                'required_unit' => 'pcs', 'covered_quantity' => '1', 'covered_unit' => 'pcs', 'covered' => true]], comparable: true, exclusionCodes: []);
    }
}
