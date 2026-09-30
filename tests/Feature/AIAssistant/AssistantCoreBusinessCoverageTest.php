<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantCoreBusinessMetadata as Metadata;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\CoreBusinessMoneyRagSource;
use App\BusinessModules\Features\Notifications\Models\Notification;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\ContractCurrentState;
use App\Models\CostCategory;
use App\Models\CustomerPortalComment;
use App\Models\CustomerRequest;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SupplementaryAgreement;
use App\Models\Specification;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Database\Eloquent\Model;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantCoreBusinessCoverageTest extends TestCase
{
    public function test_real_collector_uses_contract_state_primary_key_and_native_supplier_tax_number(): void
    {
        $fixture = $this->fixture();
        $project = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        $agreement = $this->agreement($fixture->organization, $project, 'native-state-identity');
        $state = Model::withoutEvents(fn (): ContractCurrentState => ContractCurrentState::query()->create([
            'contract_id' => $agreement->contract_id, 'current_total_amount' => '321.45', 'calculated_at' => now()]));
        $supplier = Model::withoutEvents(fn (): Supplier => Supplier::query()->create([
            'organization_id' => $fixture->organization->id, 'name' => 'Поставщик с налоговым номером', 'tax_number' => '7701234567', 'is_active' => true]));
        $money = app(CoreBusinessMoneyRagSource::class);
        $stateChunks = [...$money->collectEntity($fixture->organization->id, 'core_contract_current_state', $state->contract_id)];
        self::assertCount(1, $stateChunks);
        self::assertSame($state->contract_id, $stateChunks[0]->entityId);
        self::assertSame($project->id, $stateChunks[0]->projectId);
        self::assertSame($state->contract_id, $stateChunks[0]->metadata['contract_id']);
        self::assertSame('321.45', $stateChunks[0]->metadata['current_total_amount']);
        self::assertArrayNotHasKey('id', $stateChunks[0]->metadata);
        $source = app(\App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\CoreBusinessRagSource::class);
        $supplierChunks = [...$source->collectEntity($fixture->organization->id, 'core_supplier', $supplier->id)];
        self::assertCount(1, $supplierChunks);
        self::assertSame('7701234567', $supplierChunks[0]->metadata['tax_number']);
        self::assertArrayNotHasKey('inn', $supplierChunks[0]->metadata);
        self::assertArrayNotHasKey('ogrn', $supplierChunks[0]->metadata);
    }

    public function test_financial_child_inherits_current_parent_organization_and_foreign_parent_is_denied(): void
    {
        $fixture = $this->fixture();
        $project = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        $foreignProject = Project::factory()->create(['organization_id' => $fixture->foreignOrganization->id]);
        $own = $this->agreement($fixture->organization, $project, 'own');
        $foreign = $this->agreement($fixture->foreignOrganization, $foreignProject, 'foreign');
        $type = $this->agreementType();
        $policy = app(AssistantDataAccessPolicy::class);
        self::assertTrue($policy->canReadEntity($fixture->owner, $fixture->organization->id, $type, $own->id));
        self::assertFalse($policy->canReadEntity($fixture->owner, $fixture->organization->id, $type, $foreign->id));
        self::assertFalse($policy->canReadEntity($fixture->foreignOwner, $fixture->organization->id, $type, $own->id));
        self::assertSame('123.45', $own->change_amount);
        $own->update(['contract_id' => $foreign->contract_id]);
        self::assertFalse($policy->canReadEntity($fixture->owner, $fixture->organization->id, $type, $own->id));
    }

    public function test_parent_project_filter_applies_before_limit_for_real_member(): void
    {
        $fixture = $this->fixture();
        $deniedProject = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        $allowedProject = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        $denied = $this->agreement($fixture->organization, $deniedProject, 'denied-first');
        $allowed = $this->agreement($fixture->organization, $allowedProject, 'allowed-second');
        $member = $fixture->addMember(['contract-management' => ['contracts.view'], 'payments' => ['finance.view']]);
        $member->assignedProjects()->attach($allowedProject->id, ['role' => 'member', 'is_active' => true]);
        $policy = app(AssistantDataAccessPolicy::class);
        $type = $this->agreementType();
        self::assertFalse($policy->canReadEntity($member, $fixture->organization->id, $type, $denied->id));
        self::assertTrue($policy->canReadEntity($member, $fixture->organization->id, $type, $allowed->id));
        $query = $policy->entityQuery($member, $fixture->organization->id, $type);
        self::assertNotNull($query);
        self::assertSame([$allowed->id], $query->orderBy('id')->limit(1)->pluck('id')->all());
    }

    public function test_cached_policy_observes_current_membership_revocation(): void
    {
        $fixture = $this->fixture();
        $project = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        $child = $this->agreement($fixture->organization, $project, 'revoke');
        $policy = app(AssistantDataAccessPolicy::class);
        $type = $this->agreementType();
        self::assertTrue($policy->canReadEntity($fixture->owner, $fixture->organization->id, $type, $child->id));
        $fixture->owner->organizations()->updateExistingPivot($fixture->organization->id, ['is_active' => false]);
        self::assertFalse($policy->canReadEntity($fixture->owner, $fixture->organization->id, $type, $child->id));
        self::assertFalse($policy->canReadSource($fixture->owner, $fixture->organization->id, ['entity_type' => $type, 'entity_id' => $child->id]));
    }

    public function test_real_money_source_collects_only_the_parent_tenant_and_explicit_financial_fields(): void
    {
        $fixture = $this->fixture();
        $project = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        $foreignProject = Project::factory()->create(['organization_id' => $fixture->foreignOrganization->id]);
        $own = $this->agreement($fixture->organization, $project, 'money-own');
        $foreign = $this->agreement($fixture->foreignOrganization, $foreignProject, 'money-foreign');
        $collector = app(CoreBusinessMoneyRagSource::class);
        $chunks = [...$collector->collectEntity($fixture->organization->id, $this->agreementType(), $own->id)];
        self::assertCount(1, $chunks);
        self::assertSame($fixture->organization->id, $chunks[0]->organizationId);
        self::assertSame($project->id, $chunks[0]->projectId);
        self::assertStringContainsString('123.45', $chunks[0]->content);
        self::assertStringNotContainsString('subject_changes', $chunks[0]->content);
        self::assertSame([], [...$collector->collectEntity($fixture->organization->id, $this->agreementType(), $foreign->id)]);
    }

    public function test_specification_uses_accessible_contract_pivot_and_current_reassignment_or_revoke(): void
    {
        $fixture = $this->fixture();
        $project = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        $foreignProject = Project::factory()->create(['organization_id' => $fixture->foreignOrganization->id]);
        $own = $this->agreement($fixture->organization, $project, 'spec-own');
        $foreign = $this->agreement($fixture->foreignOrganization, $foreignProject, 'spec-foreign');
        $hidden = Specification::query()->create(['number' => 'spec-hidden', 'spec_date' => '2026-09-29', 'scope_items' => []]);
        $visible = Specification::query()->create(['number' => 'spec-visible', 'spec_date' => '2026-09-29', 'scope_items' => []]);
        $hidden->contracts()->attach($foreign->contract_id);
        $visible->contracts()->attach($own->contract_id);
        $policy = app(AssistantDataAccessPolicy::class);
        self::assertFalse($policy->canReadEntity($fixture->owner, $fixture->organization->id, 'core_specification', $hidden->id));
        self::assertTrue($policy->canReadEntity($fixture->owner, $fixture->organization->id, 'core_specification', $visible->id));
        $query = $policy->entityQuery($fixture->owner, $fixture->organization->id, 'core_specification');
        self::assertNotNull($query);
        self::assertSame([$visible->id], $query->orderBy('id')->limit(1)->pluck('id')->all());
        $visible->contracts()->sync([$foreign->contract_id]);
        self::assertFalse($policy->canReadEntity($fixture->owner, $fixture->organization->id, 'core_specification', $visible->id));
        $visible->contracts()->sync([$own->contract_id]);
        $fixture->owner->organizations()->updateExistingPivot($fixture->organization->id, ['is_active' => false]);
        self::assertFalse($policy->canReadEntity($fixture->owner, $fixture->organization->id, 'core_specification', $visible->id));
    }

    public function test_portal_comment_requires_current_author_known_morph_and_readable_parent_before_limit(): void
    {
        $fixture = $this->fixture();
        $member = $fixture->addMember(['project-management' => ['projects.view']]);
        $allowedProject = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        $hiddenProject = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        $member->assignedProjects()->attach($allowedProject->id, ['role' => 'member', 'is_active' => true]);
        $request = CustomerRequest::query()->create(['organization_id' => $fixture->organization->id, 'author_user_id' => $member->id,
            'project_id' => $allowedProject->id, 'title' => 'Проверка доступного обращения', 'request_type' => 'question', 'body' => 'Тестовые данные']);
        $otherAuthor = CustomerPortalComment::query()->create(['organization_id' => $fixture->organization->id,
            'author_user_id' => $fixture->owner->id, 'commentable_type' => CustomerRequest::class, 'commentable_id' => $request->id, 'body' => 'Чужой автор']);
        $unknownMorph = CustomerPortalComment::query()->create(['organization_id' => $fixture->organization->id,
            'author_user_id' => $member->id, 'commentable_type' => Project::class, 'commentable_id' => $allowedProject->id, 'body' => 'Недопустимый тип']);
        $visible = CustomerPortalComment::query()->create(['organization_id' => $fixture->organization->id,
            'author_user_id' => $member->id, 'commentable_type' => CustomerRequest::class, 'commentable_id' => $request->id, 'body' => 'Свой комментарий']);
        $policy = app(AssistantDataAccessPolicy::class);
        self::assertFalse($policy->canReadEntity($member, $fixture->organization->id, 'core_customer_portal_comment', $otherAuthor->id));
        self::assertFalse($policy->canReadEntity($member, $fixture->organization->id, 'core_customer_portal_comment', $unknownMorph->id));
        self::assertTrue($policy->canReadEntity($member, $fixture->organization->id, 'core_customer_portal_comment', $visible->id));
        $query = $policy->entityQuery($member, $fixture->organization->id, 'core_customer_portal_comment');
        self::assertNotNull($query);
        self::assertSame([$visible->id], $query->orderBy('id')->limit(1)->pluck('id')->all());
        $request->update(['project_id' => $hiddenProject->id]);
        self::assertFalse($policy->canReadEntity($member, $fixture->organization->id, 'core_customer_portal_comment', $visible->id));
        $request->update(['project_id' => $allowedProject->id]);
        $visible->update(['author_user_id' => $fixture->owner->id]);
        self::assertFalse($policy->canReadEntity($member, $fixture->organization->id, 'core_customer_portal_comment', $visible->id));
    }

    public function test_notification_filters_current_recipient_and_user_morph_before_limit(): void
    {
        $fixture = $this->fixture();
        $member = $fixture->addMember(['notifications' => ['notifications.view']]);
        $rows = Model::withoutEvents(function () use ($fixture, $member): array {
            $result = [];
            foreach ([[$fixture->owner->id, User::class], [$member->id, Organization::class], [$member->id, User::class]] as $index => [$recipient, $morph]) {
                $row = new Notification();
                $row->forceFill(['id' => '00000000-0000-4000-8000-00000000000'.($index + 1), 'organization_id' => $fixture->organization->id,
                    'notifiable_id' => $recipient, 'notifiable_type' => $morph, 'type' => 'testing', 'notification_type' => 'system', 'data' => []]);
                $row->save();
                $result[] = $row;
            }
            return $result;
        });
        $policy = app(AssistantDataAccessPolicy::class);
        self::assertFalse($policy->canReadEntity($member, $fixture->organization->id, 'core_notification', $rows[0]->id));
        self::assertFalse($policy->canReadEntity($member, $fixture->organization->id, 'core_notification', $rows[1]->id));
        self::assertTrue($policy->canReadEntity($member, $fixture->organization->id, 'core_notification', $rows[2]->id));
        $query = $policy->entityQuery($member, $fixture->organization->id, 'core_notification');
        self::assertNotNull($query);
        self::assertSame([$rows[2]->id], $query->orderBy('id')->limit(1)->pluck('id')->all());
        $member->organizations()->updateExistingPivot($fixture->organization->id, ['is_active' => false]);
        self::assertFalse($policy->canReadEntity($member, $fixture->organization->id, 'core_notification', $rows[2]->id));
    }

    public function test_complete_finite_collectors_execute_real_schema_queries_and_emit_only_safe_tenant_metadata(): void
    {
        $fixture = $this->fixture();
        $project = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        $financial = $this->agreement($fixture->organization, $project, 'complete-financial');
        $category = Model::withoutEvents(fn (): CostCategory => CostCategory::query()->create([
            'organization_id' => $fixture->organization->id, 'name' => 'Полная проверка каталога', 'code' => 'CORE-SCHEMA-OWN', 'is_active' => true]));
        Model::withoutEvents(fn (): CostCategory => CostCategory::query()->create([
            'organization_id' => $fixture->foreignOrganization->id, 'name' => 'Чужая статья затрат', 'code' => 'CORE-SCHEMA-FOREIGN', 'is_active' => true]));
        $records = Metadata::inventory();
        $queriedTypes = [];
        $observed = [];
        foreach (Metadata::sourceClasses() as $sourceClass) {
            $collector = app($sourceClass);
            $queriedTypes = [...$queriedTypes, ...array_keys($collector->entities())];
            foreach ($collector->collectForOrganization($fixture->organization->id) as $chunk) {
                self::assertSame($fixture->organization->id, $chunk->organizationId);
                self::assertArrayHasKey($chunk->entityType, $records);
                $record = $records[$chunk->entityType];
                self::assertTrue($record['indexed']);
                self::assertSame($collector->sourceType(), $chunk->sourceType);
                self::assertSame($record['source'], $chunk->sourceType);
                self::assertSame([], array_values(array_diff(array_keys($chunk->metadata), [...$record['rag_fields'], 'project_id'])));
                $scalarKeys = array_keys(array_filter($chunk->metadata, static fn (mixed $value): bool => $value === null || is_scalar($value)));
                self::assertSame([], array_values(array_diff($scalarKeys, [...$record['rag_fields'], 'project_id'])));
                self::assertSame($chunk->projectId, $chunk->metadata['project_id']);
                if ($record['organization_column'] !== null) {
                    self::assertSame($fixture->organization->id, (int) $chunk->metadata[$record['organization_column']]);
                }
                $observed[$chunk->entityType][(string) $chunk->entityId] = true;
            }
        }
        $expectedTypes = array_keys(array_filter($records, static fn (array $record): bool => $record['indexed']));
        sort($queriedTypes);
        sort($expectedTypes);
        self::assertSame($expectedTypes, $queriedTypes);
        self::assertTrue($observed['core_cost_category'][(string) $category->id] ?? false);
        self::assertTrue($observed[$this->agreementType()][(string) $financial->id] ?? false);
    }

    private function fixture(): AssistantRealAuthorizationFixture
    {
        return AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug'));
    }

    private function agreementType(): string
    {
        foreach (Metadata::inventory() as $type => $record) {
            if ($record['model'] === SupplementaryAgreement::class) { return $type; }
        }
        self::fail('SupplementaryAgreement must have an explicit core business record.');
    }

    private function agreement(Organization $organization, Project $project, string $name): SupplementaryAgreement
    {
        return Model::withoutEvents(function () use ($organization, $project, $name): SupplementaryAgreement {
            $contractor = Contractor::query()->create(['organization_id' => $organization->id, 'name' => $name]);
            $contract = Contract::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
                'contractor_id' => $contractor->id, 'number' => $name, 'date' => '2026-09-29', 'status' => 'active', 'total_amount' => '1000.00']);
            return SupplementaryAgreement::query()->create(['contract_id' => $contract->id, 'number' => $name,
                'agreement_date' => '2026-09-29', 'change_amount' => '123.45', 'subject_changes' => ['Уточнение объёма работ']]);
        });
    }
}
