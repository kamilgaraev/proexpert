<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\Support\EnablesImmutableAuditWriter;
use Tests\TestCase;

final class CommercialProposalDraftWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use EnablesImmutableAuditWriter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableImmutableAuditWriter();
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess', 'canUserAccessModule')->andReturn(true);
        });
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface', 'can', 'canCurrent', 'hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['web_admin']);
            $mock->shouldReceive('getUserRoles')->andReturnUsing(
                static function (User $user, ?AuthorizationContext $context = null) {
                    return $user->roleAssignments()->where('is_active', true)
                        ->when($context !== null, static fn ($query) => $query->where('context_id', $context->id))->get();
                }
            );
        });
    }

    public function test_quantity_edit_keeps_the_submitted_deal_and_other_proposal_fields(): void
    {
        $context = AdminApiTestContext::create();
        $dealId = $this->createDeal((int) $context->organization->id);
        $proposal = $this->createProposal($context, $dealId);
        $payload = $this->payload($dealId);
        $payload['line_items'][0]['quantity'] = '3';

        $this->withHeaders($context->authHeaders())->patchJson($this->url($proposal['id']), $payload)
            ->assertOk()->assertJsonPath('data.links.crm_deal_id', $dealId);
        $fresh = $this->withHeaders($context->authHeaders())->getJson($this->url($proposal['id']))
            ->assertOk()->assertJsonPath('data.links.crm_deal_id', $dealId)
            ->assertJsonPath('data.customer_email', 'proposal@example.invalid');
        self::assertSame(3.0, (float) $fresh->json('data.current_version.sections.0.line_items.0.quantity'));
        $this->assertDatabaseHas('commercial_proposals', ['id' => $proposal['id'], 'crm_deal_id' => $dealId]);
    }

    public function test_partial_patch_preserves_omitted_fields_and_explicit_null_clears_the_deal(): void
    {
        $context = AdminApiTestContext::create();
        $dealId = $this->createDeal((int) $context->organization->id);
        $proposal = $this->createProposal($context, $dealId);

        $this->withHeaders($context->authHeaders())->patchJson($this->url($proposal['id']), ['title' => 'Обновлённый черновик'])
            ->assertOk()->assertJsonPath('data.links.crm_deal_id', $dealId)
            ->assertJsonPath('data.customer_email', 'proposal@example.invalid');
        $this->assertDatabaseHas('commercial_proposals', ['id' => $proposal['id'], 'valid_until' => '2026-12-31']);

        $this->withHeaders($context->authHeaders())->patchJson($this->url($proposal['id']), ['crm_deal_id' => null])
            ->assertOk()->assertJsonPath('data.links.crm_deal_id', null)
            ->assertJsonPath('data.customer_email', 'proposal@example.invalid');
        $this->assertDatabaseHas('commercial_proposals', ['id' => $proposal['id'], 'crm_deal_id' => null]);
    }

    public function test_patch_rejects_a_deal_from_another_organization_without_changing_the_original_link(): void
    {
        $context = AdminApiTestContext::create();
        $foreign = AdminApiTestContext::create();
        $dealId = $this->createDeal((int) $context->organization->id);
        $foreignDealId = $this->createDeal((int) $foreign->organization->id);
        $proposal = $this->createProposal($context, $dealId);

        $response = $this->withHeaders($context->authHeaders())->patchJson($this->url($proposal['id']), ['crm_deal_id' => $foreignDealId]);
        self::assertContains($response->getStatusCode(), [404, 422], $response->getContent());
        $this->assertDatabaseHas('commercial_proposals', ['id' => $proposal['id'], 'crm_deal_id' => $dealId]);
    }

    public function test_new_version_from_an_approved_proposal_keeps_v1_and_creates_an_independent_draft(): void
    {
        $context = AdminApiTestContext::create();
        $dealId = $this->createDeal((int) $context->organization->id);
        $proposal = $this->createProposal($context, $dealId);
        $this->withHeaders($context->authHeaders())->postJson($this->url($proposal['id']).'/approval/request', [])->assertOk();
        $this->withHeaders($context->authHeaders())->postJson($this->url($proposal['id']).'/approval/decision', ['decision' => 'approved'])->assertOk();
        $payload = $this->payload($dealId);
        $payload['title'] = 'Редакция 2';
        $payload['line_items'][0]['quantity'] = '3';

        $response = $this->withHeaders($context->authHeaders())->postJson($this->url($proposal['id']).'/versions', $payload)
            ->assertCreated()->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.current_version.version_number', 2)
            ->assertJsonPath('data.links.crm_deal_id', $dealId);
        $versionId = $response->json('data.current_version.id');
        self::assertNotSame($proposal['current_version']['id'], $versionId);
        self::assertSame(3.0, (float) $response->json('data.current_version.sections.0.line_items.0.quantity'));
        $oldVersion = DB::table('commercial_proposal_versions')->where('id', $proposal['current_version']['id'])->first();
        self::assertNotNull($oldVersion);
        self::assertSame(2.5, (float) json_decode($oldVersion->sections_snapshot, true, 512, JSON_THROW_ON_ERROR)[0]['line_items'][0]['quantity']);
        $this->assertDatabaseHas('commercial_proposals', ['id' => $proposal['id'], 'current_version_id' => $versionId, 'customer_email' => 'proposal@example.invalid', 'crm_deal_id' => $dealId]);
    }

    public function test_new_version_is_blocked_while_the_current_version_is_being_reviewed(): void
    {
        $context = AdminApiTestContext::create();
        $proposal = $this->createProposal($context, $this->createDeal((int) $context->organization->id));
        $this->withHeaders($context->authHeaders())->postJson($this->url($proposal['id']).'/approval/request', [])->assertOk();

        $this->withHeaders($context->authHeaders())->postJson($this->url($proposal['id']).'/versions', ['title' => 'Запрещённая редакция'])
            ->assertStatus(409);
        $this->assertDatabaseHas('commercial_proposals', ['id' => $proposal['id'], 'current_version_id' => $proposal['current_version']['id'], 'status' => 'internal_review']);
        self::assertSame(1, DB::table('commercial_proposal_versions')->where('commercial_proposal_id', $proposal['id'])->count());
        self::assertSame(1, DB::table('commercial_proposal_approvals')->where('commercial_proposal_id', $proposal['id'])->where('status', 'pending')->count());
    }

    public function test_new_version_from_a_draft_keeps_the_original_snapshot_and_customer_fields(): void
    {
        $context = AdminApiTestContext::create();
        $dealId = $this->createDeal((int) $context->organization->id);
        $proposal = $this->createProposal($context, $dealId);
        $payload = $this->payload($dealId);
        $payload['line_items'][0]['quantity'] = '3';
        $payload['sections'][0]['body'] = 'Учебная редакция 2';

        $response = $this->withHeaders($context->authHeaders())->postJson($this->url($proposal['id']).'/versions', $payload)
            ->assertCreated()->assertJsonPath('data.current_version.version_number', 2)
            ->assertJsonPath('data.customer_email', 'proposal@example.invalid')
            ->assertJsonPath('data.links.crm_deal_id', $dealId);
        self::assertSame(3.0, (float) $response->json('data.current_version.sections.0.line_items.0.quantity'));
        $oldSnapshot = DB::table('commercial_proposal_versions')->where('id', $proposal['current_version']['id'])->value('sections_snapshot');
        self::assertSame(2.5, (float) json_decode($oldSnapshot, true, 512, JSON_THROW_ON_ERROR)[0]['line_items'][0]['quantity']);
        self::assertSame(2, DB::table('commercial_proposal_versions')->where('commercial_proposal_id', $proposal['id'])->count());
    }

    public function test_explicit_empty_line_items_removes_the_positions_without_restoring_old_rows(): void
    {
        $context = AdminApiTestContext::create();
        $proposal = $this->createProposal($context, $this->createDeal((int) $context->organization->id));
        $this->withHeaders($context->authHeaders())->patchJson($this->url($proposal['id']), [
            'sections' => [['title' => 'Раздел без позиций', 'body' => 'Осталось только описание']],
            'line_items' => [],
        ])->assertOk()->assertJsonCount(0, 'data.current_version.sections.0.line_items');
        self::assertSame(0, DB::table('commercial_proposal_line_items')->where('commercial_proposal_version_id', $proposal['current_version']['id'])->count());
        self::assertSame(1, DB::table('commercial_proposal_sections')->where('commercial_proposal_version_id', $proposal['current_version']['id'])->count());
    }

    private function createProposal(AdminApiTestContext $context, string $dealId): array
    {
        return $this->withHeaders($context->authHeaders())->postJson($this->url(), $this->payload($dealId))
            ->assertCreated()->assertJsonPath('data.links.crm_deal_id', $dealId)->json('data');
    }

    private function payload(string $dealId): array
    {
        return [
            'title' => 'Учебное коммерческое предложение',
            'customer_name' => 'Условный клиент',
            'customer_email' => 'proposal@example.invalid',
            'crm_deal_id' => $dealId,
            'currency' => 'RUB',
            'valid_until' => '2026-12-31',
            'sections' => [['title' => 'Состав', 'body' => 'Учебное описание']],
            'line_items' => [['section_index' => 0, 'title' => 'Учебная позиция', 'quantity' => '2.5', 'unit' => 'м²', 'unit_price' => '100', 'vat_rate' => '0']],
        ];
    }

    private function createDeal(int $organizationId): string
    {
        $companyId = (string) Str::uuid();
        DB::table('crm_companies')->insert([
            'id' => $companyId,
            'organization_id' => $organizationId,
            'name' => 'Учебная компания',
            'company_type' => 'legal_entity',
            'roles' => '[]',
            'status' => 'active',
            'tags' => '[]',
            'custom_fields' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $id = (string) Str::uuid();
        DB::table('crm_deals')->insert([
            'id' => $id,
            'organization_id' => $organizationId,
            'company_id' => $companyId,
            'title' => 'Учебная сделка',
            'pipeline_code' => 'default',
            'stage_code' => 'qualification',
            'status' => 'open',
            'currency' => 'RUB',
            'custom_fields' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function url(string $id = ''): string
    {
        return '/api/v1/admin/commercial-proposals'.($id !== '' ? '/'.$id : '');
    }
}
