<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Core\MultiOrganization\Contracts\ContractorSharingInterface;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use App\Services\Contract\ContractService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class CrmDealConversionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_validate_and_convert_create_project_contract_and_source_links(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowModuleAccess();
        $this->allowAdminAccess();
        $this->allowContractorSharing();

        $source = $this->createConversionSource($context);

        $previewResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/crm/deals/{$source['deal_id']}/conversion/preview", []);

        $previewResponse->assertOk();
        $previewResponse->assertJsonPath('success', true);
        $previewResponse->assertJsonPath('data.ready_to_convert', true);
        $previewResponse->assertJsonPath('data.amount.amount_visible', true);
        $previewResponse->assertJsonPath('data.project.mode', 'create');
        $previewResponse->assertJsonPath('data.project.budget_amount_context.contour', 'project_planned_cost');
        $previewResponse->assertJsonPath('data.project.budget_amount_context.label', 'Плановая стоимость проекта');
        $previewResponse->assertJsonPath('data.contract.amount_context.contour', 'contract_amount');
        $previewResponse->assertJsonPath('data.budget_seed.kind', 'deferred_budget_seed');
        $previewResponse->assertJsonPath('data.budget_seed.creates_budget_lines', false);
        $previewResponse->assertJsonPath('data.contract.fields.contractor_id', $source['contractor_id']);

        $validationPayload = $this->conversionPayload($previewResponse->json('data.preview_hash'), $source['contractor_id']);
        $validateResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/crm/deals/{$source['deal_id']}/conversion/validate", $validationPayload);

        $validateResponse->assertOk();
        $validateResponse->assertJsonPath('data.ready_to_convert', true);

        $convertResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/crm/deals/{$source['deal_id']}/conversion/convert", array_merge($validationPayload, [
                'idempotency_key' => 'crm-conversion-key-1',
                'preview_hash' => $validateResponse->json('data.preview_hash'),
            ]));

        $convertResponse->assertCreated();
        $convertResponse->assertJsonPath('success', true);
        $convertResponse->assertJsonPath('data.status', 'converted');
        $convertResponse->assertJsonPath('data.source_links.tender_id', $source['tender_id']);
        $convertResponse->assertJsonPath('data.source_links.commercial_proposal_id', $source['proposal_id']);

        $projectId = (int) $convertResponse->json('data.project.id');
        $contractId = (int) $convertResponse->json('data.contract.id');
        $project = Project::query()->findOrFail($projectId);

        $this->assertSame('project_planned_cost', $project->additional_info['budget_amount_context']['contour'] ?? null);
        $this->assertSame('crm_conversion', $project->additional_info['budget_amount_context']['source'] ?? null);
        $this->assertFalse($project->additional_info['budget_amount_context']['creates_budget_lines'] ?? true);

        $this->assertDatabaseHas('projects', [
            'id' => $projectId,
            'organization_id' => $context->organization->id,
            'name' => 'Складской комплекс',
        ]);
        $this->assertDatabaseHas('contracts', [
            'id' => $contractId,
            'organization_id' => $context->organization->id,
            'project_id' => $projectId,
            'contractor_id' => $source['contractor_id'],
            'number' => 'КП-2026-01',
        ]);
        $this->assertDatabaseHas('crm_deals', [
            'id' => $source['deal_id'],
            'project_id' => $projectId,
            'contract_id' => $contractId,
        ]);
        $this->assertDatabaseHas('tenders', [
            'id' => $source['tender_id'],
            'project_id' => $projectId,
            'contract_id' => $contractId,
        ]);
        $this->assertDatabaseHas('commercial_proposals', [
            'id' => $source['proposal_id'],
            'project_id' => $projectId,
            'contract_id' => $contractId,
        ]);
        $this->assertDatabaseHas('crm_timeline_events', [
            'entity_type' => 'deals',
            'entity_id' => $source['deal_id'],
            'event_type' => 'conversion_completed',
        ]);
        $this->assertDatabaseHas('tender_timeline_events', [
            'tender_id' => $source['tender_id'],
            'event_type' => 'conversion_completed',
        ]);
        $this->assertDatabaseHas('commercial_proposal_timeline_events', [
            'commercial_proposal_id' => $source['proposal_id'],
            'event_type' => 'conversion_completed',
        ]);
        $this->assertDatabaseHas('crm_conversion_operations', [
            'organization_id' => $context->organization->id,
            'idempotency_key' => 'crm-conversion-key-1',
            'status' => 'completed',
            'project_id' => $projectId,
            'contract_id' => $contractId,
        ]);
    }

    public function test_preview_hides_amounts_without_amount_permission(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowModuleAccess();
        $this->allowAdminAccessWithoutAmountPermissions();
        $this->allowContractorSharing();

        $source = $this->createConversionSource($context);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/crm/deals/{$source['deal_id']}/conversion/preview", []);

        $response->assertOk();
        $response->assertJsonPath('data.amount.amount_visible', false);
        $response->assertJsonPath('data.amount.value', null);
        $response->assertJsonPath('data.project.fields.budget_amount', null);
        $response->assertJsonPath('data.contract.fields.base_amount', null);
        $response->assertJsonPath('data.contract.fields.is_fixed_amount', false);
    }

    public function test_validate_returns_blockers_for_missing_required_fields(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowModuleAccess();
        $this->allowAdminAccess();
        $this->allowContractorSharing();

        $source = $this->createConversionSource($context, linkedContractor: false);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/crm/deals/{$source['deal_id']}/conversion/validate", [
                'project' => [
                    'mode' => 'create',
                    'fields' => [
                        'name' => '',
                    ],
                ],
                'contract' => [
                    'mode' => 'create',
                    'fields' => [
                        'number' => '',
                        'is_fixed_amount' => true,
                    ],
                ],
                'counterparty' => [
                    'contractor_id' => null,
                ],
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.ready_to_convert', false);

        $blockerKeys = collect($response->json('data.blockers'))->pluck('key')->all();

        $this->assertContains('project_name_required', $blockerKeys);
        $this->assertContains('contract_number_required', $blockerKeys);
        $this->assertContains('contract_contractor_id_required', $blockerKeys);
    }

    public function test_validate_blocks_inaccessible_manual_contractor(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowModuleAccess();
        $this->allowAdminAccess();
        $this->denyContractorSharing();

        $source = $this->createConversionSource($context, linkedContractor: false);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/crm/deals/{$source['deal_id']}/conversion/validate", $this->conversionPayload(null, $source['contractor_id']));

        $response->assertOk();
        $response->assertJsonPath('data.ready_to_convert', false);
        $response->assertJsonPath('data.contract.fields.contractor_id', null);

        $blockerKeys = collect($response->json('data.blockers'))->pluck('key')->all();

        $this->assertContains('contract_contractor_id_required', $blockerKeys);
    }

    public function test_validate_blocks_inaccessible_supplier(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowModuleAccess();
        $this->allowAdminAccess();
        $this->allowContractorSharing();

        $source = $this->createConversionSource($context, linkedContractor: false);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/crm/deals/{$source['deal_id']}/conversion/validate", [
                'project' => [
                    'mode' => 'create',
                    'fields' => [
                        'name' => 'Складской комплекс',
                        'status' => 'draft',
                    ],
                ],
                'contract' => [
                    'mode' => 'create',
                    'fields' => [
                        'number' => 'SUP-2026-01',
                        'date' => now()->toDateString(),
                        'status' => 'draft',
                        'contract_side_type' => 'general_contractor_to_supplier',
                        'is_fixed_amount' => false,
                    ],
                ],
                'counterparty' => [
                    'supplier_id' => 999999,
                ],
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.ready_to_convert', false);
        $response->assertJsonPath('data.contract.fields.supplier_id', null);

        $blockerKeys = collect($response->json('data.blockers'))->pluck('key')->all();

        $this->assertContains('contract_supplier_id_required', $blockerKeys);
    }

    public function test_convert_replays_same_idempotency_key_without_creating_duplicates(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowModuleAccess();
        $this->allowAdminAccess();
        $this->allowContractorSharing();

        $source = $this->createConversionSource($context);
        $payload = $this->validatedPayload($context, $source, 'crm-conversion-replay');

        $firstResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/crm/deals/{$source['deal_id']}/conversion/convert", $payload);
        $firstResponse->assertCreated();

        $secondResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/crm/deals/{$source['deal_id']}/conversion/convert", $payload);

        $secondResponse->assertOk();
        $secondResponse->assertJsonPath('data.status', 'already_converted');
        $secondResponse->assertJsonPath('data.idempotent_replay', true);
        $secondResponse->assertJsonPath('data.project.id', $firstResponse->json('data.project.id'));
        $secondResponse->assertJsonPath('data.contract.id', $firstResponse->json('data.contract.id'));
        $this->assertSame(1, Project::query()->where('organization_id', $context->organization->id)->count());
        $this->assertSame(1, Contract::query()->where('organization_id', $context->organization->id)->count());
    }

    public function test_convert_blocks_duplicate_creation_after_completed_conversion_with_new_key(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowModuleAccess();
        $this->allowAdminAccess();
        $this->allowContractorSharing();

        $source = $this->createConversionSource($context);
        $payload = $this->validatedPayload($context, $source, 'crm-conversion-original');

        $firstResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/crm/deals/{$source['deal_id']}/conversion/convert", $payload);
        $firstResponse->assertCreated();

        $newKeyPayload = $this->validatedPayload($context, $source, 'crm-conversion-new-key');

        $secondResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/crm/deals/{$source['deal_id']}/conversion/convert", $newKeyPayload);

        $secondResponse->assertOk();
        $secondResponse->assertJsonPath('data.status', 'already_converted');
        $secondResponse->assertJsonPath('data.idempotent_replay', false);
        $secondResponse->assertJsonPath('data.project.id', $firstResponse->json('data.project.id'));
        $secondResponse->assertJsonPath('data.contract.id', $firstResponse->json('data.contract.id'));
        $this->assertSame(1, Project::query()->where('organization_id', $context->organization->id)->count());
        $this->assertSame(1, Contract::query()->where('organization_id', $context->organization->id)->count());
    }

    public function test_convert_rolls_back_project_and_links_when_contract_creation_fails(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowModuleAccess();
        $this->allowAdminAccess();
        $this->allowContractorSharing();

        $source = $this->createConversionSource($context);
        $payload = $this->validatedPayload($context, $source, 'crm-conversion-rollback');

        $realContractService = $this->app->make(ContractService::class);
        $this->mock(ContractService::class, function (MockInterface $mock) use ($realContractService): void {
            $mock->shouldReceive('createContract')
                ->once()
                ->withAnyArgs()
                ->andThrow(new RuntimeException('contract create failed'));
            $mock->shouldIgnoreMissing($realContractService);
        });

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/crm/deals/{$source['deal_id']}/conversion/convert", $payload);

        $response->assertServerError();
        $this->assertSame(0, Project::query()->where('organization_id', $context->organization->id)->count());
        $this->assertSame(0, Contract::query()->where('organization_id', $context->organization->id)->count());
        $this->assertDatabaseHas('crm_deals', [
            'id' => $source['deal_id'],
            'project_id' => null,
            'contract_id' => null,
        ]);
        $this->assertDatabaseMissing('crm_conversion_operations', [
            'idempotency_key' => 'crm-conversion-rollback',
            'status' => 'completed',
        ]);
    }

    public function test_convert_is_forbidden_without_deal_link_permission(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowModuleAccess();
        $this->denyDealLinkPermission();
        $this->allowContractorSharing();

        $source = $this->createConversionSource($context);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/crm/deals/{$source['deal_id']}/conversion/convert", [
                'idempotency_key' => 'crm-conversion-forbidden',
            ]);

        $response->assertForbidden();
    }

    private function validatedPayload(AdminApiTestContext $context, array $source, string $idempotencyKey): array
    {
        $previewResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/crm/deals/{$source['deal_id']}/conversion/preview", []);

        $previewResponse->assertOk();

        $validationPayload = $this->conversionPayload($previewResponse->json('data.preview_hash'), $source['contractor_id']);

        $validateResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/crm/deals/{$source['deal_id']}/conversion/validate", $validationPayload);

        $validateResponse->assertOk();
        $validateResponse->assertJsonPath('data.ready_to_convert', true);

        return array_merge($validationPayload, [
            'preview_hash' => $validateResponse->json('data.preview_hash'),
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    private function conversionPayload(?string $previewHash, int $contractorId): array
    {
        return [
            'project' => [
                'mode' => 'create',
                'fields' => [
                    'name' => 'Складской комплекс',
                    'status' => 'draft',
                ],
            ],
            'contract' => [
                'mode' => 'create',
                'fields' => [
                    'number' => 'КП-2026-01',
                    'date' => '2026-06-16',
                    'status' => 'draft',
                    'contract_side_type' => 'customer_to_general_contractor',
                    'base_amount' => 1500000,
                    'total_amount' => 1500000,
                    'is_fixed_amount' => true,
                ],
            ],
            'counterparty' => [
                'contractor_id' => $contractorId,
            ],
            'preview_hash' => $previewHash,
        ];
    }

    private function createConversionSource(AdminApiTestContext $context, bool $linkedContractor = true): array
    {
        $contractor = Contractor::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Генподрядчик',
            'inn' => (string) random_int(1000000000, 9999999999),
            'contractor_type' => Contractor::TYPE_MANUAL,
        ]);

        $companyId = $this->createCrmCompany($context->organization->id, [
            'linked_contractor_id' => $linkedContractor ? $contractor->id : null,
            'inn' => $linkedContractor ? null : '7701000001',
        ]);
        $dealId = $this->createCrmDeal($context->organization->id, $companyId, [
            'amount' => 1500000,
        ]);
        $sourceId = $this->createTenderSource($context->organization->id);
        $tenderId = $this->createTender($context->organization->id, $sourceId, [
            'crm_deal_id' => $dealId,
            'number' => 'Т-2026-01',
            'title' => 'Реконструкция склада',
            'status' => 'won',
            'winner_amount' => 1500000,
        ]);
        $proposalId = $this->createCommercialProposal($context->organization->id, [
            'crm_deal_id' => $dealId,
            'tender_id' => $tenderId,
            'number' => 'КП-2026-01',
            'title' => 'КП на реконструкцию склада',
            'status' => 'accepted',
            'total_amount' => 1500000,
        ]);

        DB::table('tenders')->where('id', $tenderId)->update([
            'commercial_proposal_id' => $proposalId,
            'updated_at' => now(),
        ]);

        return [
            'contractor_id' => $contractor->id,
            'company_id' => $companyId,
            'deal_id' => $dealId,
            'tender_id' => $tenderId,
            'proposal_id' => $proposalId,
        ];
    }

    private function createCrmCompany(int $organizationId, array $overrides = []): string
    {
        $id = (string) Str::uuid();
        $now = now();

        DB::table('crm_companies')->insert(array_merge([
            'id' => $id,
            'organization_id' => $organizationId,
            'owner_user_id' => null,
            'linked_organization_id' => null,
            'linked_contractor_id' => null,
            'source_id' => null,
            'merged_into_id' => null,
            'source_ref_type' => null,
            'source_ref_id' => null,
            'name' => 'Строй Плюс',
            'legal_name' => 'ООО Строй Плюс',
            'company_type' => 'legal_entity',
            'roles' => '[]',
            'status' => 'active',
            'inn' => null,
            'kpp' => null,
            'ogrn' => null,
            'phone' => null,
            'email' => null,
            'website' => null,
            'legal_address' => 'Москва, Примерная улица, 1',
            'actual_address' => 'Москва, Примерная улица, 1',
            'tags' => '[]',
            'custom_fields' => '{}',
            'notes' => null,
            'last_activity_at' => null,
            'created_by_user_id' => null,
            'updated_by_user_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ], $overrides));

        return $id;
    }

    private function createCrmDeal(int $organizationId, string $companyId, array $overrides = []): string
    {
        $id = (string) Str::uuid();
        $now = now();

        DB::table('crm_deals')->insert(array_merge([
            'id' => $id,
            'organization_id' => $organizationId,
            'company_id' => $companyId,
            'primary_contact_id' => null,
            'lead_id' => null,
            'owner_user_id' => null,
            'project_id' => null,
            'contract_id' => null,
            'pipeline_id' => null,
            'stage_id' => null,
            'source_id' => null,
            'title' => 'Реконструкция склада',
            'pipeline_code' => 'default',
            'stage_code' => 'won',
            'status' => 'won',
            'amount' => null,
            'currency' => 'RUB',
            'probability' => null,
            'expected_close_at' => null,
            'won_at' => $now,
            'lost_at' => null,
            'lost_reason' => null,
            'next_activity_at' => null,
            'custom_fields' => '{}',
            'created_by_user_id' => null,
            'updated_by_user_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ], $overrides));

        return $id;
    }

    private function createTenderSource(int $organizationId): string
    {
        $id = (string) Str::uuid();

        DB::table('tender_sources')->insert([
            'id' => $id,
            'organization_id' => $organizationId,
            'code' => 'manual-'.Str::lower(Str::random(8)),
            'label' => 'Ручной ввод',
            'source_type' => 'manual',
            'base_url' => null,
            'settings' => '{}',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function createTender(int $organizationId, string $sourceId, array $overrides = []): string
    {
        $id = (string) Str::uuid();
        $now = now();

        DB::table('tenders')->insert(array_merge([
            'id' => $id,
            'organization_id' => $organizationId,
            'source_id' => $sourceId,
            'customer_company_id' => null,
            'customer_contact_id' => null,
            'owner_user_id' => null,
            'crm_deal_id' => null,
            'commercial_proposal_id' => null,
            'project_id' => null,
            'contract_id' => null,
            'number' => 'Т-'.Str::upper(Str::random(8)),
            'external_number' => null,
            'external_url' => null,
            'title' => 'Тендер',
            'description' => null,
            'customer_name' => 'ООО Строй Плюс',
            'customer_inn' => null,
            'customer_kpp' => null,
            'customer_ogrn' => null,
            'status' => 'won',
            'priority' => 'normal',
            'risk_level' => 'medium',
            'initial_max_price' => null,
            'budget_missing_reason' => null,
            'expected_bid_amount' => null,
            'final_bid_amount' => null,
            'final_bid_amount_missing_reason' => null,
            'winner_amount' => null,
            'currency' => 'RUB',
            'published_at' => null,
            'questions_deadline_at' => null,
            'submission_deadline_at' => null,
            'submitted_at' => null,
            'submitted_by_user_id' => null,
            'submission_confirmation_file_id' => null,
            'submission_confirmation_url' => null,
            'opening_at' => null,
            'auction_at' => null,
            'result_expected_at' => null,
            'result_published_at' => null,
            'next_deadline_at' => null,
            'go_no_go_decision' => 'pending',
            'go_no_go_reason' => null,
            'decided_by_user_id' => null,
            'decided_at' => null,
            'lost_reason' => null,
            'cancel_reason' => null,
            'winner_name' => null,
            'requirements_summary' => null,
            'analysis_summary' => null,
            'requirements' => '{}',
            'evaluation_criteria' => '{}',
            'metadata' => '{}',
            'created_by_user_id' => null,
            'updated_by_user_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ], $overrides));

        return $id;
    }

    private function createCommercialProposal(int $organizationId, array $overrides = []): string
    {
        $id = (string) Str::uuid();
        $now = now();

        DB::table('commercial_proposals')->insert(array_merge([
            'id' => $id,
            'organization_id' => $organizationId,
            'current_version_id' => null,
            'accepted_version_id' => null,
            'crm_deal_id' => null,
            'tender_id' => null,
            'presale_estimate_id' => null,
            'project_id' => null,
            'contract_id' => null,
            'number' => 'КП-'.Str::upper(Str::random(8)),
            'title' => 'Коммерческое предложение',
            'status' => 'accepted',
            'customer_name' => 'ООО Строй Плюс',
            'customer_email' => null,
            'customer_phone' => null,
            'subtotal_amount' => null,
            'discount_amount' => null,
            'vat_amount' => null,
            'total_amount' => null,
            'currency' => 'RUB',
            'valid_until' => null,
            'sent_at' => null,
            'customer_decision_at' => null,
            'archived_at' => null,
            'created_by_user_id' => null,
            'updated_by_user_id' => null,
            'metadata' => '{}',
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ], $overrides));

        return $id;
    }

    private function allowModuleAccess(): void
    {
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')
                ->andReturnUsing(static fn (int $organizationId, string $moduleSlug): bool => $moduleSlug === 'crm');
        });
    }

    private function allowAdminAccess(): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturn(true);
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['web_admin']);
            $mock->shouldReceive('getUserRoles')->andReturnUsing(
                static function (User $user, ?AuthorizationContext $context = null) {
                    return $user->roleAssignments()
                        ->where('is_active', true)
                        ->when($context !== null, static fn ($query) => $query->where('context_id', $context->id))
                        ->get();
                }
            );
        });
    }

    private function allowAdminAccessWithoutAmountPermissions(): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturnUsing(
                static fn (User $user, string $permission, array $context = []): bool => ! in_array($permission, [
                    'crm.amounts.view',
                    'tenders.amounts.view',
                    'commercial_proposals.amounts.view',
                ], true)
            );
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['web_admin']);
            $mock->shouldReceive('getUserRoles')->andReturnUsing(
                static function (User $user, ?AuthorizationContext $context = null) {
                    return $user->roleAssignments()
                        ->where('is_active', true)
                        ->when($context !== null, static fn ($query) => $query->where('context_id', $context->id))
                        ->get();
                }
            );
        });
    }

    private function denyDealLinkPermission(): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturnUsing(
                static fn (User $user, string $permission, array $context = []): bool => $permission !== 'crm.deals.link'
            );
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['web_admin']);
            $mock->shouldReceive('getUserRoles')->andReturnUsing(
                static function (User $user, ?AuthorizationContext $context = null) {
                    return $user->roleAssignments()
                        ->where('is_active', true)
                        ->when($context !== null, static fn ($query) => $query->where('context_id', $context->id))
                        ->get();
                }
            );
        });
    }

    private function allowContractorSharing(): void
    {
        $this->mock(ContractorSharingInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canUseContractor')->andReturn(true);
            $mock->shouldReceive('getAvailableContractors')->andReturn(collect());
        });
    }

    private function denyContractorSharing(): void
    {
        $this->mock(ContractorSharingInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canUseContractor')->andReturn(false);
            $mock->shouldReceive('getAvailableContractors')->andReturn(collect());
        });
    }
}
