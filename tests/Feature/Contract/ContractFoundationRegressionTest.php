<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentObligation;
use App\Enums\Contract\ContractSideTypeEnum;
use App\Enums\ProjectOrganizationRole;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Organization;
use App\Models\Project;
use App\Rules\AvailableContractProject;
use App\Services\Contract\ContractPartySnapshotService;
use App\Services\Contract\ProjectContractPartyResolver;
use App\Services\LegalArchive\Obligations\LegalDocumentObligationService;
use App\Services\Project\ProjectParticipantService;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

final class ContractFoundationRegressionTest extends TestCase
{
    use \Tests\Support\EnablesImmutableAuditWriter;

    public function test_subcontractor_keeps_two_independent_superior_contracts_in_one_project(): void
    {
        $this->enableImmutableAuditWriter();
        $executor = Organization::factory()->create();
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        $projectOwner = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $projectOwner->id]);
        $participants = app(ProjectParticipantService::class);
        $participants->attach($project, $executor->id, ProjectOrganizationRole::SUBCONTRACTOR);
        foreach ([$first, $second] as $superior) {
            $participants->attach($project, $superior->id, ProjectOrganizationRole::CONTRACTOR);
        }
        $self = Contractor::create([
            'organization_id' => $executor->id, 'source_organization_id' => $executor->id,
            'name' => $executor->name, 'contractor_type' => 'invited_organization',
        ]);
        $authorization = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $authorization->shouldReceive('can')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $authorization);
        $views = new \App\Services\Contract\ContractOrganizationViewService($authorization);
        $creator = \App\Models\User::factory()->create(['current_organization_id' => $executor->id]);
        $contracts = [];
        foreach ([$first, $second] as $superior) {
            $request = \App\Http\Requests\Api\V1\Admin\Contract\StoreContractRequest::create('/api/v1/admin/contracts', 'POST', [
                'project_id' => $project->id, 'contractor_id' => $self->id, 'superior_organization_id' => $superior->id,
                'number' => 'MULTI-'.$superior->id, 'date' => '2026-09-19', 'subject' => 'Работы субподряда',
                'base_amount' => 1000, 'total_amount' => 1000, 'is_fixed_amount' => true,
                'contract_side_type' => 'subcontract', 'direction' => 'income', 'idempotency_key' => 'multi-'.$superior->id,
            ]);
            $request->setUserResolver(fn () => $creator);
            $request->attributes->set('current_organization_id', $executor->id);
            self::assertTrue($request->authorize());
            $validator = Validator::make($request->all(), $request->rules(), $request->messages());
            $request->withValidator($validator);
            self::assertTrue($validator->passes(), json_encode($validator->errors()->toArray()));
            $request->setValidator($validator);
            $dto = $request->toDto();
            $contract = app(\App\Services\Contract\ContractSideMutationService::class)->create($executor->id, $dto, actorId: $creator->id);
            $contracts[] = $contract;
        }
        foreach ([$first, $second, $executor] as $organization) {
            $actor = \App\Models\User::factory()->create(['current_organization_id' => $organization->id]);
            $visible = $views->list($actor, $organization->id)->getCollection()->pluck('contract_id')->all();
            $expected = $organization->id === $executor->id
                ? [$contracts[0]->id, $contracts[1]->id]
                : [$contracts[$organization->id === $first->id ? 0 : 1]->id];
            self::assertEqualsCanonicalizing($expected, $visible);
        }
        $participants->setActiveState($project, $first->id, false);
        foreach ($contracts as $index => $contract) {
            app(ContractPartySnapshotService::class)->syncParties($contract->fresh());
            self::assertSame([$first, $second][$index]->id, $contract->firstParty()->firstOrFail()->linked_organization_id);
            self::assertSame($executor->id, $contract->secondParty()->firstOrFail()->linked_organization_id);
        }
        self::assertSame($second->id, app(ProjectContractPartyResolver::class)->selectedActiveParticipant($project, ProjectOrganizationRole::CONTRACTOR, $second->id)?->id);
    }

    public function test_historical_report_is_scoped_and_does_not_create_or_rewrite_parties(): void
    {
        $owner = Organization::factory()->create();
        $other = Organization::factory()->create();
        $contracts = [];
        foreach ([$owner, $other] as $organization) {
            $contracts[] = Contract::create([
                'organization_id' => $organization->id, 'number' => 'LEGACY-'.$organization->id,
                'date' => '2026-09-19', 'status' => 'draft', 'total_amount' => 100,
            ]);
        }
        $before = array_map(static fn (Contract $contract): array => $contract->fresh()->getAttributes(), $contracts);
        $partiesBefore = \App\Models\ContractParty::count();
        $kernel = $this->app->make(\Illuminate\Contracts\Console\Kernel::class);
        self::assertSame(0, $kernel->call('contracts:report-party-inconsistencies', ['--organization' => $owner->id]));
        $lines = array_values(array_filter(explode("\n", trim($kernel->output()))));
        self::assertCount(1, $lines);
        $entry = json_decode($lines[0], true, 16, JSON_THROW_ON_ERROR);
        self::assertSame($contracts[0]->id, $entry['contract_id']);
        self::assertSame($owner->id, $entry['organization_id']);
        self::assertSame(['missing_party_snapshot'], $entry['issues']);
        self::assertTrue($entry['requires_manual_review']);
        self::assertSame($partiesBefore, \App\Models\ContractParty::count());
        self::assertSame($before, array_map(static fn (Contract $contract): array => $contract->fresh()->getAttributes(), $contracts));
        self::assertSame(2, $kernel->call('contracts:report-party-inconsistencies', []));
    }

    public function test_preview_accepts_external_customer_and_only_authorized_shared_contractors(): void
    {
        $owner = Organization::factory()->create();
        $other = Organization::factory()->create();
        $customer = \App\Models\Counterparty::create([
            'organization_id' => $owner->id, 'name' => 'Внешний заказчик', 'inn' => '7701234567',
        ]);
        $project = Project::factory()->create(['organization_id' => $owner->id, 'customer_counterparty_id' => $customer->id]);
        $actor = \App\Models\User::factory()->create(['current_organization_id' => $owner->id]);
        $authorization = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $authorization->shouldReceive('can')->andReturn(true);
        $preview = new \App\Services\Contract\ContractPartyPreviewService(
            $authorization, app(ProjectContractPartyResolver::class), app(ContractPartySnapshotService::class),
        );
        $result = $preview->preview($actor, $owner->id, [
            'project_id' => $project->id, 'contract_side_type' => 'general_contract', 'direction' => 'income',
        ]);
        self::assertNull($result['reason']);
        self::assertSame($customer->id, $result['first_party']['counterparty_id']);
        self::assertSame($owner->id, $result['second_party']['linked_organization_id']);
        $shared = Contractor::create([
            'organization_id' => $other->id, 'name' => 'Общий исполнитель', 'contractor_type' => 'manual',
        ]);
        $sharing = \Mockery::mock(\App\BusinessModules\Core\MultiOrganization\Contracts\ContractorSharingInterface::class);
        $sharing->shouldReceive('canUseContractor')->with($shared->id, $owner->id)->once()->andReturn(true);
        $sharing->shouldReceive('canUseContractor')->with($shared->id, $owner->id)->once()->andReturn(false);
        $this->app->instance(\App\BusinessModules\Core\MultiOrganization\Contracts\ContractorSharingInterface::class, $sharing);
        $input = ['project_id' => $project->id, 'contract_side_type' => 'subcontract', 'direction' => 'expense', 'contractor_id' => $shared->id];
        $allowed = $preview->preview($actor, $owner->id, $input);
        self::assertNull($allowed['reason']);
        self::assertSame($shared->name, $allowed['second_party']['name']);
        $denied = $preview->preview($actor, $owner->id, $input);
        self::assertNotNull($denied['reason']);
        self::assertNull($denied['second_party']);
    }

    public function test_direct_scheme_uses_customer_without_general_contractor_and_preserves_saved_parties(): void
    {
        $customer = Organization::factory()->create();
        $executor = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $customer->id, 'contracting_scheme' => 'direct']);
        $participants = app(ProjectParticipantService::class);
        $participants->attach($project, $customer->id, ProjectOrganizationRole::CUSTOMER);
        $participants->attach($project, $executor->id, ProjectOrganizationRole::CONTRACTOR);
        $resolver = app(ProjectContractPartyResolver::class);
        self::assertTrue($resolver->shouldAutofillSelfAsContractor($project, $executor->id, ContractSideTypeEnum::CONTRACT, $customer->id, 'income'));
        $authorization = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $authorization->shouldReceive('can')->andReturn(true);
        $actor = \App\Models\User::factory()->create(['current_organization_id' => $executor->id]);
        $preview = new \App\Services\Contract\ContractPartyPreviewService($authorization, $resolver, app(ContractPartySnapshotService::class));
        $result = $preview->preview($actor, $executor->id, [
            'project_id' => $project->id, 'contract_side_type' => 'contract', 'direction' => 'income',
        ]);
        self::assertSame('customer', $result['superior_role']);
        self::assertNull($result['reason']);
        self::assertSame($customer->id, $result['first_party']['linked_organization_id']);
        self::assertSame($executor->id, $result['second_party']['linked_organization_id']);
        $self = Contractor::create([
            'organization_id' => $executor->id, 'source_organization_id' => $executor->id,
            'name' => $executor->name, 'contractor_type' => 'invited_organization',
        ]);
        $contract = Contract::create([
            'organization_id' => $executor->id, 'project_id' => $project->id,
            'contractor_id' => $self->id, 'superior_organization_id' => $customer->id,
            'contract_side_type' => 'contract', 'number' => 'DIRECT-1',
            'date' => '2026-09-19', 'status' => 'draft', 'total_amount' => 100,
        ]);
        app(ContractPartySnapshotService::class)->syncParties($contract);
        $project->update(['contracting_scheme' => 'general_contractor']);
        app(ContractPartySnapshotService::class)->syncParties($contract->fresh());
        self::assertSame($customer->id, $contract->firstParty()->firstOrFail()->linked_organization_id);
        self::assertSame($executor->id, $contract->secondParty()->firstOrFail()->linked_organization_id);
    }

    public function test_manual_executor_without_tax_number_keeps_saved_payment_reference(): void
    {
        $owner = Organization::factory()->create();
        $contractor = Contractor::create(['organization_id' => $owner->id, 'name' => 'Исполнитель', 'contractor_type' => 'manual']);
        $contract = Contract::create([
            'organization_id' => $owner->id, 'contractor_id' => $contractor->id,
            'contract_side_type' => 'subcontract', 'number' => 'PAYEE-1', 'date' => '2026-09-19',
            'status' => 'draft', 'total_amount' => 100,
        ]);
        app(ContractPartySnapshotService::class)->syncParties($contract, true);
        $parties = app(\App\Services\Contract\ContractPaymentPartyResolver::class)->resolve($contract);
        self::assertSame($owner->id, $parties['payer_organization_id']);
        self::assertSame($contractor->id, $parties['payee_contractor_id']);
        self::assertNull($parties['payer_contractor_id']);
    }

    public function test_general_contractor_is_unique_on_assignment_role_change_and_reactivation(): void
    {
        $owner = Organization::factory()->create();
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        $third = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $owner->id]);
        $participants = app(ProjectParticipantService::class);
        $participants->attach($project, $first->id, ProjectOrganizationRole::GENERAL_CONTRACTOR, confirmedCapabilities: true);
        $participants->attach($project, $second->id, ProjectOrganizationRole::CONTRACTOR, confirmedCapabilities: true);
        foreach ([
            fn () => $participants->attach($project, $third->id, ProjectOrganizationRole::GENERAL_CONTRACTOR, confirmedCapabilities: true),
            fn () => $participants->updateRole($project, $second->id, ProjectOrganizationRole::GENERAL_CONTRACTOR, confirmedCapabilities: true),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('Duplicate general contractor must be rejected');
            } catch (\App\Exceptions\BusinessLogicException $exception) {
                self::assertSame(trans_message('project.unique_general_contractor_conflict'), $exception->getMessage());
            }
        }
        $participants->setActiveState($project, $first->id, false);
        $participants->updateRole($project, $second->id, ProjectOrganizationRole::GENERAL_CONTRACTOR, confirmedCapabilities: true);
        try {
            $participants->setActiveState($project, $first->id, true);
            self::fail('Conflicting reactivation must be rejected');
        } catch (\App\Exceptions\BusinessLogicException $exception) {
            self::assertSame(trans_message('project.unique_general_contractor_conflict'), $exception->getMessage());
        }
        try {
            \Illuminate\Support\Facades\DB::table('project_organization')->insert([
                'project_id' => $project->id, 'organization_id' => $third->id,
                'role' => 'contractor', 'role_new' => 'general_contractor', 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            self::fail('Storage must prevent a duplicate general contractor');
        } catch (\Illuminate\Database\QueryException $exception) {
            self::assertSame('23505', (string) $exception->getCode());
            self::assertStringContainsString('project_single_active_general_contractor', $exception->getMessage());
        }
    }

    public function test_income_subcontract_uses_selected_superior_without_changing_expense_contracts(): void
    {
        $owner = Organization::factory()->create();
        $superior = Organization::factory()->create();
        $other = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $superior->id]);
        $participants = app(ProjectParticipantService::class);
        $participants->attach($project, $owner->id, ProjectOrganizationRole::SUBCONTRACTOR);
        $participants->attach($project, $superior->id, ProjectOrganizationRole::CONTRACTOR);
        $participants->attach($project, $other->id, ProjectOrganizationRole::CONTRACTOR);
        $resolver = app(ProjectContractPartyResolver::class);
        self::assertTrue($resolver->shouldAutofillSelfAsContractor($project, $owner->id, ContractSideTypeEnum::SUBCONTRACT, $superior->id, 'income'));
        self::assertFalse($resolver->shouldAutofillSelfAsContractor($project, $owner->id, ContractSideTypeEnum::SUBCONTRACT, null, 'income'));
        self::assertFalse($resolver->shouldAutofillSelfAsContractor($project, $owner->id, ContractSideTypeEnum::SUBCONTRACT, $superior->id, 'expense'));
        $authorization = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $authorization->shouldReceive('can')->andReturn(true);
        $actor = \App\Models\User::factory()->create(['current_organization_id' => $owner->id]);
        $preview = new \App\Services\Contract\ContractPartyPreviewService($authorization, $resolver, app(ContractPartySnapshotService::class));
        $beforeCount = Contract::count();
        $input = ['project_id' => $project->id, 'contract_side_type' => 'subcontract', 'direction' => 'income'];
        $ambiguous = $preview->preview($actor, $owner->id, $input);
        self::assertCount(2, $ambiguous['superior_candidates']);
        self::assertNotNull($ambiguous['reason']);
        $selected = $preview->preview($actor, $owner->id, $input + ['superior_organization_id' => $superior->id]);
        self::assertSame($superior->id, $selected['first_party']['linked_organization_id']);
        self::assertSame($owner->id, $selected['second_party']['linked_organization_id']);
        self::assertNull($selected['reason']);
        self::assertSame($beforeCount, Contract::count());
        $executor = Contractor::create([
            'organization_id' => $owner->id, 'source_organization_id' => $owner->id,
            'name' => $owner->name, 'contractor_type' => 'invited_organization',
        ]);
        $contract = Contract::create([
            'organization_id' => $owner->id, 'project_id' => $project->id,
            'contractor_id' => $executor->id, 'superior_organization_id' => $superior->id,
            'contract_side_type' => 'subcontract', 'number' => 'FOUNDATION-1',
            'date' => '2026-09-19', 'status' => 'draft', 'total_amount' => 1000,
        ]);
        app(ContractPartySnapshotService::class)->syncParties($contract, true);
        self::assertSame($superior->id, $contract->firstParty()->firstOrFail()->linked_organization_id);
        self::assertSame($owner->id, $contract->secondParty()->firstOrFail()->linked_organization_id);
        $executor->update(['source_organization_id' => $other->id]);
        $contract->update(['superior_organization_id' => null]);
        app(ContractPartySnapshotService::class)->syncParties($contract->fresh(), true);
        self::assertSame($owner->id, $contract->firstParty()->firstOrFail()->linked_organization_id);
        self::assertSame($other->id, $contract->secondParty()->firstOrFail()->linked_organization_id);
    }

    public function test_project_rule_accepts_active_participant_and_rejects_unrelated_organization(): void
    {
        $owner = Organization::factory()->create();
        $participant = Organization::factory()->create();
        $outsider = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $owner->id]);
        app(ProjectParticipantService::class)->attach($project, $participant->id, ProjectOrganizationRole::CONTRACTOR);
        foreach ([$owner->id => true, $participant->id => true, $outsider->id => false] as $organizationId => $expected) {
            self::assertSame($expected, Validator::make(['project_id' => $project->id], [
                'project_id' => [AvailableContractProject::forOrganization($organizationId)],
            ])->passes());
        }
    }

    public function test_equal_obligation_titles_remain_distinct_and_replay_preserves_execution(): void
    {
        $organization = Organization::factory()->create();
        $document = LegalArchiveDocument::create([
            'organization_id' => $organization->id, 'title' => 'Договор', 'document_type' => 'contract',
            'status' => 'active', 'structured_fields' => ['obligations' => [
                ['id' => 'advance', 'title' => 'Оплата', 'amount' => 100],
                ['id' => 'final', 'title' => 'Оплата', 'amount' => 200],
            ]],
        ]);
        $service = app(LegalDocumentObligationService::class);
        $obligations = $service->syncFromEffectiveDocument($document);
        self::assertCount(2, $obligations);
        $first = $obligations->first();
        $first->update(['status' => 'completed', 'completed_at' => now(), 'evidence' => ['file_id' => 42]]);
        $replayed = $service->syncFromEffectiveDocument($document);
        self::assertSame($obligations->pluck('id')->all(), $replayed->pluck('id')->all());
        self::assertSame('completed', $first->fresh()->status);
        self::assertSame(['file_id' => 42], $first->fresh()->evidence);
        self::assertSame(2, LegalDocumentObligation::where('document_id', $document->id)->count());
    }

    public function test_duplicate_definition_ids_roll_back_all_obligations(): void
    {
        $organization = Organization::factory()->create();
        $document = LegalArchiveDocument::create([
            'organization_id' => $organization->id, 'title' => 'Договор', 'document_type' => 'contract',
            'status' => 'active', 'structured_fields' => ['obligations' => [
                ['id' => 'same', 'title' => 'Первая'], ['id' => 'same', 'title' => 'Вторая'],
            ]],
        ]);
        try {
            app(LegalDocumentObligationService::class)->syncFromEffectiveDocument($document);
            self::fail('Duplicate definition must be rejected');
        } catch (\DomainException) {
            self::assertSame(0, LegalDocumentObligation::where('document_id', $document->id)->count());
        }
    }

    public function test_legacy_obligation_is_adopted_without_duplicate_or_execution_reset(): void
    {
        $organization = Organization::factory()->create();
        $definition = ['id' => 'payment', 'title' => 'Оплата', 'amount' => 100];
        $document = LegalArchiveDocument::create([
            'organization_id' => $organization->id, 'title' => 'Договор', 'document_type' => 'contract',
            'status' => 'active', 'structured_fields' => ['obligations' => [$definition]],
        ]);
        $legacy = LegalDocumentObligation::create([
            'document_id' => $document->id, 'organization_id' => $organization->id,
            'title' => 'Оплата', 'amount' => 100, 'status' => 'completed', 'completed_at' => now(),
        ]);
        $result = app(LegalDocumentObligationService::class)->syncFromEffectiveDocument($document);
        self::assertSame($legacy->id, $result->first()->id);
        self::assertSame('completed', $result->first()->status);
        self::assertNotNull($legacy->fresh()->source_key);
        self::assertSame(1, LegalDocumentObligation::where('document_id', $document->id)->count());
    }

    public function test_ambiguous_legacy_obligation_requires_review_instead_of_duplicating_payments(): void
    {
        $organization = Organization::factory()->create();
        $document = LegalArchiveDocument::create([
            'organization_id' => $organization->id, 'title' => 'Договор', 'document_type' => 'contract',
            'status' => 'active', 'structured_fields' => ['obligations' => [
                ['id' => 'one', 'title' => 'Оплата', 'amount' => 100],
                ['id' => 'two', 'title' => 'Оплата', 'amount' => 100],
            ]],
        ]);
        $legacy = LegalDocumentObligation::create([
            'document_id' => $document->id, 'organization_id' => $organization->id,
            'title' => 'Оплата', 'amount' => 100, 'status' => 'completed',
        ]);
        try {
            app(LegalDocumentObligationService::class)->syncFromEffectiveDocument($document);
            self::fail('Ambiguous legacy obligation must not be duplicated');
        } catch (\DomainException $exception) {
            self::assertSame(trans_message('contracts.obligation_legacy_review_required'), $exception->getMessage());
            self::assertNull($legacy->fresh()->source_key);
            self::assertSame(1, LegalDocumentObligation::where('document_id', $document->id)->count());
        }
    }
}
