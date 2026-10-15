<?php

declare(strict_types=1);

namespace Tests\Security;

use App\BusinessModules\Features\Crm\Models\CrmCompany;
use App\BusinessModules\Features\Crm\Models\CrmDeal;
use App\BusinessModules\Features\Crm\Models\CrmConversionOperation;
use App\BusinessModules\Features\Crm\Models\CrmImportBatch;
use App\BusinessModules\Features\Crm\Models\CrmImportRow;
use App\BusinessModules\Features\Crm\Models\CrmLead;
use App\BusinessModules\Features\Crm\Services\CrmTargetAccessService;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Enums\AuthSessionStatus;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\User;
use App\Models\UserAuthSession;
use App\Services\Auth\WebAuthTokenService;
use Illuminate\Support\Str;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\Support\EnablesImmutableAuditWriter;
use Tests\TestCase;

final class CrmConversionProjectScopeSecurityTest extends TestCase
{
    use EnablesImmutableAuditWriter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableImmutableAuditWriter();
    }

    public function test_preview_does_not_disclose_an_unassigned_project_selected_by_id(): void
    {
        [$fixture, $actor, $allowed, $restricted, $deal] = $this->context();

        $this->postJson($this->url($deal, 'preview'), [
            'project' => ['mode' => 'reuse', 'id' => $allowed->id],
        ], $this->headers($actor))->assertOk()->assertJsonPath('data.project.existing.id', $allowed->id);

        $this->postJson($this->url($deal, 'preview'), [
            'project' => ['mode' => 'reuse', 'id' => $restricted->id],
        ], $this->headers($actor))->assertNotFound();
    }

    public function test_contract_project_is_checked_independently_on_preview_validate_and_convert(): void
    {
        [$fixture, $actor, $allowed, $restricted, $deal] = $this->context();
        $contract = $this->contract($restricted);
        $payload = $this->reuse($allowed, $contract);
        $headers = $this->headers($actor);
        foreach (['preview', 'validate', 'convert'] as $action) {
            $this->postJson($this->url($deal, $action), $payload, $headers)->assertNotFound();
        }
        self::assertNull($deal->fresh()->project_id);
        self::assertNull($deal->fresh()->contract_id);
        self::assertSame(0, CrmConversionOperation::query()->where('crm_deal_id', $deal->id)->count());
    }

    public function test_create_mode_and_automatic_deal_reference_cannot_disclose_a_restricted_target(): void
    {
        [$fixture, $actor, $allowed, $restricted, $deal] = $this->context();
        $headers = $this->headers($actor);
        $this->postJson($this->url($deal, 'preview'), [
            'project' => ['mode' => 'create', 'id' => $restricted->id],
        ], $headers)->assertNotFound();
        $deal->update(['project_id' => $restricted->id]);
        $this->postJson($this->url($deal, 'preview'), [], $headers)->assertNotFound();
        $deal->update(['project_id' => null, 'contract_id' => $this->contract($restricted)->id]);
        $this->postJson($this->url($deal, 'preview'), [], $headers)->assertNotFound();
    }

    public function test_crud_and_link_routes_cannot_attach_restricted_targets_or_change_the_deal(): void
    {
        [$fixture, $actor, $allowed, $restricted, $deal] = $this->context([
            'crm.deals.create', 'crm.deals.update', 'crm.deals.link',
        ]);
        $headers = $this->headers($actor);
        $contract = $this->contract($restricted);
        $root = '/api/v1/admin/crm/deals';
        $this->postJson($root, ['company_id' => $deal->company_id, 'title' => 'Denied', 'project_id' => $restricted->id], $headers)->assertNotFound();
        $this->patchJson($root.'/'.$deal->id, ['project_id' => $restricted->id, 'title' => 'Denied'], $headers)->assertNotFound();
        $this->postJson($root.'/'.$deal->id.'/links', ['project_id' => $allowed->id, 'contract_id' => $contract->id], $headers)->assertNotFound();
        self::assertSame('CRM security deal', $deal->fresh()->title);
        self::assertNull($deal->fresh()->project_id);
        self::assertNull($deal->fresh()->contract_id);
        self::assertSame(1, CrmDeal::query()->forOrganization($fixture->organization->id)->count());

        $this->postJson($root, ['company_id' => $deal->company_id, 'title' => 'Allowed', 'project_id' => $allowed->id], $headers)
            ->assertCreated()->assertJsonPath('data.project_id', $allowed->id);
        $this->patchJson($root.'/'.$deal->id, ['project_id' => $allowed->id], $headers)->assertOk();
        $this->postJson($root.'/'.$deal->id.'/links', ['project_id' => null, 'contract_id' => null], $headers)->assertOk();
        self::assertNull($deal->fresh()->project_id);
    }

    public function test_original_link_permission_does_not_require_deal_view_and_reuse_convert_still_works(): void
    {
        [$fixture, $actor, $allowed, $restricted, $deal] = $this->context(['crm.deals.link']);
        $contract = $this->contract($allowed);
        $payload = $this->reuse($allowed, $contract);
        $headers = $this->headers($actor);
        $this->postJson($this->url($deal, 'validate'), $payload, $headers)->assertOk()->assertJsonPath('data.ready_to_convert', true);
        $this->postJson($this->url($deal, 'convert'), $payload, $headers)->assertCreated()
            ->assertJsonPath('data.project.id', $allowed->id)->assertJsonPath('data.contract.id', $contract->id);
        $this->postJson($this->url($deal, 'convert'), $payload, $headers)->assertOk()->assertJsonPath('data.idempotent_replay', true);
    }

    public function test_replay_with_same_or_new_key_rechecks_revoked_project_assignment(): void
    {
        [$fixture, $actor, $allowed, $restricted, $deal] = $this->context();
        $payload = $this->reuse($allowed, $this->contract($allowed));
        $headers = $this->headers($actor);
        $this->postJson($this->url($deal, 'convert'), $payload, $headers)->assertCreated();
        $allowed->users()->updateExistingPivot($actor->id, ['is_active' => false]);
        $this->postJson($this->url($deal, 'convert'), $payload, $headers)->assertNotFound();
        $payload['idempotency_key'] = 'crm-new-key-'.Str::uuid();
        $this->postJson($this->url($deal, 'convert'), $payload, $headers)->assertNotFound();
        self::assertSame(1, CrmConversionOperation::query()->where('crm_deal_id', $deal->id)->count());
    }

    public function test_replay_rechecks_current_contract_project_and_rejects_a_key_from_another_deal(): void
    {
        [$fixture, $actor, $allowed, $restricted, $deal] = $this->context();
        $contract = $this->contract($allowed);
        $payload = $this->reuse($allowed, $contract);
        $headers = $this->headers($actor);
        $this->postJson($this->url($deal, 'convert'), $payload, $headers)->assertCreated();
        $other = $deal->replicate();
        $other->project_id = null;
        $other->contract_id = null;
        $other->save();
        $this->postJson($this->url($other, 'convert'), $payload, $headers)->assertConflict();
        $contract->update(['project_id' => $restricted->id]);
        $this->postJson($this->url($deal, 'convert'), $payload, $headers)->assertNotFound();
        $payload['idempotency_key'] = 'crm-other-key-'.Str::uuid();
        $this->postJson($this->url($deal, 'convert'), $payload, $headers)->assertNotFound();
    }

    public function test_projectless_archived_multi_project_and_all_projects_contract_semantics_are_kept(): void
    {
        [$fixture, $actor, $allowed, $restricted, $deal] = $this->context();
        $allowed->update(['is_archived' => true]);
        $projectless = $this->contract($allowed);
        $projectless->update(['project_id' => null]);
        $multi = $this->contract($restricted);
        $multi->update(['is_multi_project' => true, 'project_id' => null]);
        $multi->projects()->attach([$allowed->id, $restricted->id]);
        $headers = $this->headers($actor);
        foreach ([$projectless, $multi] as $contract) {
            $this->postJson($this->url($deal, 'preview'), $this->reuse($allowed, $contract), $headers)->assertOk()
                ->assertJsonPath('data.contract.existing.id', $contract->id);
        }
        $restrictedContract = $this->contract($restricted);
        $this->postJson($this->url($deal, 'preview'), $this->reuse($allowed, $restrictedContract), $headers)->assertNotFound();
        $fixture->organization->users()->updateExistingPivot($actor->id, ['project_access_mode' => 'all_projects']);
        $this->postJson($this->url($deal, 'preview'), $this->reuse($restricted, $restrictedContract), $headers)->assertOk();
    }

    public function test_warmed_role_or_membership_does_not_authorize_target_access_after_revocation(): void
    {
        [$fixture, $actor, $allowed] = $this->context();
        $access = app(CrmTargetAccessService::class);
        self::assertTrue($actor->can('crm.deals.view', ['organization_id' => $fixture->organization->id]));
        self::assertSame($allowed->id, $access->project($fixture->organization->id, $actor, $allowed->id, 'crm.deals.view')->id);
        UserRoleAssignment::query()->where('user_id', $actor->id)->update(['is_active' => false]);
        try {
            $access->project($fixture->organization->id, $actor, $allowed->id, 'crm.deals.view');
            self::fail('Revoked role must deny target access');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            self::assertTrue(true);
        }
        UserRoleAssignment::query()->where('user_id', $actor->id)->update(['is_active' => true]);
        $fixture->organization->users()->updateExistingPivot($actor->id, ['is_active' => false]);
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $access->project($fixture->organization->id, $actor, $allowed->id, 'crm.deals.view');
    }

    public function test_create_create_assigns_only_new_project_and_replays_until_assignment_is_revoked(): void
    {
        [$fixture, $actor, $allowed, $restricted, $deal] = $this->context();
        $payload = $this->creation($fixture->organization->id);
        $headers = $this->headers($actor);
        $response = $this->postJson($this->url($deal, 'convert'), $payload, $headers);
        $response->assertCreated()->assertJsonPath('data.status', 'converted');
        $project = Project::query()->findOrFail($response->json('data.project.id'));
        self::assertSame($fixture->organization->id, $project->organization_id);
        self::assertTrue($project->users()->where('users.id', $actor->id)->wherePivot('is_active', true)->exists());
        self::assertTrue($allowed->users()->where('users.id', $actor->id)->wherePivot('is_active', true)->exists());
        self::assertFalse($restricted->users()->where('users.id', $actor->id)->exists());
        self::assertSame($project->id, $deal->fresh()->project_id);
        $this->postJson($this->url($deal, 'convert'), $payload, $headers)->assertOk()->assertJsonPath('data.idempotent_replay', true);
        $newKey = array_replace($payload, ['idempotency_key' => 'crm-created-project-new-key-'.Str::uuid()]);
        $this->postJson($this->url($deal, 'convert'), $newKey, $headers)->assertOk()->assertJsonPath('data.idempotent_replay', false);
        $project->users()->updateExistingPivot($actor->id, ['is_active' => false]);
        $this->postJson($this->url($deal, 'convert'), $payload, $headers)->assertNotFound();
        $this->postJson($this->url($deal, 'convert'), $newKey, $headers)->assertNotFound();
        self::assertSame(1, CrmConversionOperation::query()->where('crm_deal_id', $deal->id)->count());
    }

    public function test_create_contract_on_reused_accessible_project_still_works(): void
    {
        [$fixture, $actor, $allowed, $restricted, $deal] = $this->context();
        $payload = $this->creation($fixture->organization->id);
        $payload['project'] = ['mode' => 'reuse', 'id' => $allowed->id];
        $this->postJson($this->url($deal, 'convert'), $payload, $this->headers($actor))->assertCreated()
            ->assertJsonPath('data.project.id', $allowed->id)->assertJsonPath('data.contract.project_id', $allowed->id);
    }

    public function test_import_create_and_update_keep_import_permission_and_deny_restricted_references_atomically(): void
    {
        [$fixture, $actor, $allowed, $restricted, $deal] = $this->context(['crm.import.confirm']);
        $headers = $this->headers($actor);
        foreach (['create', 'update'] as $decision) {
            $batch = CrmImportBatch::query()->create([
                'organization_id' => $fixture->organization->id, 'entity_type' => 'deals',
                'source_format' => 'csv', 'status' => 'previewed', 'total_rows' => 1, 'uploaded_by_user_id' => $actor->id,
            ]);
            $values = ['title' => 'Imported '.$decision, 'company_id' => $deal->company_id, 'project_id' => $restricted->id];
            $row = CrmImportRow::query()->create([
                'batch_id' => $batch->id, 'row_number' => 1, 'normalized_values' => $values,
                'decision' => $decision, 'status' => 'accepted', 'created_entity_id' => $decision === 'update' ? $deal->id : null,
            ]);
            $url = '/api/v1/admin/crm/imports/'.$batch->id.'/confirm';
            $this->postJson($url, [], $headers)->assertNotFound();
            self::assertSame('previewed', $batch->fresh()->status);
            self::assertSame('accepted', $row->fresh()->status);
            self::assertNull($deal->fresh()->project_id);
            self::assertSame('CRM security deal', $deal->fresh()->title);
            $values['project_id'] = $allowed->id;
            $row->update(['normalized_values' => $values]);
            $this->postJson($url, [], $headers)->assertOk();
            self::assertSame('confirmed', $batch->fresh()->status);
            self::assertSame('imported', $row->fresh()->status);
        }
        self::assertSame($allowed->id, $deal->fresh()->project_id);
        self::assertSame('Imported update', $deal->fresh()->title);
    }

    public function test_lead_conversion_keeps_its_original_permission_without_deal_create(): void
    {
        [$fixture, $actor] = $this->context(['crm.leads.convert']);
        $lead = CrmLead::query()->create(['organization_id' => $fixture->organization->id, 'title' => 'Lead security']);
        $this->postJson('/api/v1/admin/crm/leads/'.$lead->id.'/convert', [
            'company' => ['name' => 'Converted company'], 'deal' => ['title' => 'Converted deal'],
        ], $this->headers($actor))->assertOk()->assertJsonPath('data.deal.title', 'Converted deal');
        self::assertSame('converted', $lead->fresh()->status);
    }

    private function context(array $permissions = ['crm.deals.view', 'crm.deals.link']): array
    {
        $fixture = AssistantRealAuthorizationFixture::create(['working-entry', 'sales-contractors', 'finance-contracts']);
        $actor = $fixture->addMember([
            'crm' => $permissions, 'project-management' => ['projects.create'], 'contract-management' => ['contracts.create'],
        ]);
        $allowed = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        $restricted = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        $allowed->users()->attach($actor->id, ['role' => 'member', 'is_active' => true]);
        $company = CrmCompany::query()->create([
            'organization_id' => $fixture->organization->id, 'name' => 'CRM security company',
            'legal_name' => 'CRM security company', 'status' => 'active',
        ]);
        $deal = CrmDeal::query()->create([
            'organization_id' => $fixture->organization->id, 'company_id' => $company->id,
            'title' => 'CRM security deal', 'pipeline_code' => 'default', 'stage_code' => 'won',
            'status' => 'won', 'currency' => 'RUB', 'custom_fields' => [],
        ]);

        return [$fixture, $actor, $allowed, $restricted, $deal];
    }

    private function contract(Project $project): Contract
    {
        return Contract::query()->create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id,
            'number' => 'CRM-SEC-'.Str::uuid(), 'date' => '2026-10-15', 'status' => 'draft',
            'base_amount' => 1000, 'total_amount' => 1000, 'is_fixed_amount' => true,
            'is_multi_project' => false, 'is_self_execution' => true, 'currency' => 'RUB',
        ]);
    }

    private function reuse(Project $project, Contract $contract): array
    {
        return [
            'project' => ['mode' => 'reuse', 'id' => $project->id],
            'contract' => ['mode' => 'reuse', 'id' => $contract->id],
            'idempotency_key' => 'crm-security-key-'.Str::uuid(),
        ];
    }

    private function creation(int $organizationId): array
    {
        $contractor = Contractor::query()->create([
            'organization_id' => $organizationId, 'name' => 'CRM counterparty',
            'inn' => (string) random_int(1000000000, 9999999999), 'contractor_type' => Contractor::TYPE_MANUAL,
        ]);

        return [
            'project' => ['mode' => 'create', 'fields' => ['name' => 'New CRM project', 'status' => 'draft']],
            'contract' => ['mode' => 'create', 'fields' => [
                'number' => 'NEW-'.Str::uuid(), 'date' => '2026-10-15', 'status' => 'draft',
                'contract_side_type' => 'general_contract', 'base_amount' => 1000, 'total_amount' => 1000,
            ]],
            'counterparty' => ['contractor_id' => $contractor->id], 'idempotency_key' => 'crm-create-key-'.Str::uuid(),
        ];
    }

    private function url(CrmDeal $deal, string $action): string
    {
        return "/api/v1/admin/crm/deals/{$deal->id}/conversion/{$action}";
    }

    private function headers(User $actor): array
    {
        $sessionUuid = (string) Str::uuid();
        UserAuthSession::query()->create([
            'user_id' => $actor->id, 'organization_id' => $actor->current_organization_id,
            'session_uuid' => $sessionUuid, 'device_fingerprint' => hash('sha256', $sessionUuid),
            'device_name' => 'CRM security regression', 'ip_address' => '127.0.0.1',
            'risk_score' => 0, 'risk_flags' => [], 'status' => AuthSessionStatus::Active,
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        $tokens = app(WebAuthTokenService::class)->issue($actor, 'admin', $sessionUuid, (int) $actor->current_organization_id, false);

        return ['Authorization' => 'Bearer '.$tokens->accessToken, 'Accept' => 'application/json', 'Origin' => 'https://admin.1мост.рф'];
    }
}
