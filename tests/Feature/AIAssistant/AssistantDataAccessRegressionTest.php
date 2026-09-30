<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Models\RagChunk;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagRetriever;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportAccessService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\Support\RagTestEmbedding;
use Tests\TestCase;

final class AssistantDataAccessRegressionTest extends TestCase
{
    use RefreshDatabase;

    private AssistantDataAccessPolicy $policy;
    private bool $permissions = true;
    private bool $modules = true;
    private array $deniedPermissions = [];
    private array $deniedModuleSlugs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('canCurrent')->andReturnUsing(fn (User $user, string $permission): bool => $this->permissions && ! in_array($permission, $this->deniedPermissions, true));
        $authorization->shouldReceive('forCurrentChecks')->andReturnSelf();
        $authorization->shouldReceive('getUserRoles')->andReturn(collect());
        $modules = Mockery::mock(OrganizationEntitlementService::class);
        $modules->shouldReceive('getEffectiveModules')->andReturnUsing(fn () => $this->modules
            ? collect(['ai-assistant', 'project-management', 'reports', 'budget-estimates', 'contract-management', 'payments', 'procurement', 'site-requests'])->filter(fn (string $slug): bool => ! in_array($slug, $this->deniedModuleSlugs, true))->map(static fn (string $slug): object => (object) ['slug' => $slug]) : collect());
        $this->policy = new AssistantDataAccessPolicy($authorization, new UserProjectAccessService, $modules);
        $this->app->instance(AssistantDataAccessPolicy::class, $this->policy);
        config(['ai-assistant.rag.max_chunks' => 8, 'ai-assistant.rag.min_similarity' => 0.1]);
    }

    public function test_current_membership_project_assignment_and_entity_existence_are_required(): void
    {
        [$organization, $actor, $visible, $private] = $this->fixtures();
        $foreign = Project::factory()->create();
        $this->assertTrue($this->policy->canReadEntity($actor, $organization->id, 'project', $visible->id));
        $this->assertFalse($this->policy->canReadEntity($actor, $organization->id, 'project', $private->id));
        $this->assertFalse($this->policy->canReadEntity($actor, $organization->id, 'project', $foreign->id));
        $this->assertFalse($this->policy->canReadEntity($actor, $organization->id, 'contract', 999999));
        $this->assertFalse($this->policy->canReadSource($actor, $organization->id, [
            'source_type' => 'contract', 'entity_type' => 'unknown', 'entity_id' => 999999, 'project_id' => $visible->id,
        ]));
        $actor->assignedProjects()->updateExistingPivot($visible->id, ['is_active' => false]);
        $this->assertFalse($this->policy->canReadEntity($actor, $organization->id, 'project', $visible->id));
        $actor->current_organization_id = $foreign->organization_id;
        $this->assertFalse($this->policy->canReadDomain($actor, $organization->id, 'projects'));
    }

    public function test_permission_and_module_revocation_take_effect_on_the_same_policy_instance(): void
    {
        [$organization, $actor, $visible] = $this->fixtures();
        $this->assertTrue($this->policy->canReadEntity($actor, $organization->id, 'project', $visible->id));
        $this->permissions = false;
        $this->assertFalse($this->policy->canReadEntity($actor, $organization->id, 'project', $visible->id));
        $this->permissions = true;
        $this->modules = false;
        $this->assertFalse($this->policy->canReadEntity($actor, $organization->id, 'project', $visible->id));
    }

    public function test_inaccessible_high_rank_sources_do_not_starve_semantic_or_lexical_search(): void
    {
        [$organization, $actor, $visible, $private] = $this->fixtures();
        for ($index = 0; $index < 40; $index++) {
            $this->index($organization->id, $visible->id, 'project', (string) (900000 + $index), 'Скрытая стройка', [1.0, 0.0]);
        }
        $this->index($organization->id, $private->id, 'project', (string) $private->id, 'Скрытая стройка', [1.0, 0.0]);
        $this->index($organization->id, $visible->id, 'project', (string) $visible->id, 'Доступная стройка', [0.9, 0.1]);
        foreach ([false, true] as $failEmbedding) {
            $provider = new class($failEmbedding) implements RagEmbeddingProviderInterface {
                public function __construct(private bool $fail) {}
                public function embed(string $text, string $purpose = self::PURPOSE_DOCUMENT): array
                {
                    if ($this->fail) { throw new RuntimeException('unavailable'); }
                    return RagTestEmbedding::fromLeadingValues([1.0, 0.0]);
                }
                public function provider(): string { return 'test'; }
                public function model(): string { return 'test'; }
                public function dimensions(): int { return RagTestEmbedding::DIMENSIONS; }
            };
            $results = (new RagRetriever($provider, new UserProjectAccessService, $this->policy))->search('стройка', $organization->id, $actor);
            $this->assertCount(1, $results);
            $this->assertSame('Доступная стройка', $results[0]->title);
        }
    }

    public function test_report_download_rechecks_each_current_reader_and_revoked_project_access(): void
    {
        [$organization, $owner, $visible] = $this->fixtures();
        $viewer = User::factory()->create(['current_organization_id' => $organization->id, 'is_active' => true]);
        $viewer->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'assigned_projects']);
        $viewer->assignedProjects()->attach($visible->id, ['is_active' => true, 'role' => 'member']);
        $service = new AssistantReportAccessService($this->policy);
        $registered = $service->register('org-'.$organization->id.'/users/'.$owner->id.'/reports/assistant/test.pdf', $organization, $owner,
            [['entity_type' => 'project', 'entity_id' => (string) $visible->id]], ['projects']);
        $token = basename(dirname($registered['download_url']));
        $this->assertSame($organization->id, (int) $service->resolve($token, $viewer)->organization_id);
        $viewer->assignedProjects()->updateExistingPivot($visible->id, ['is_active' => false]);
        $this->expectException(AccessDeniedHttpException::class);
        $service->resolve($token, $viewer);
    }

    public function test_report_without_verified_provenance_cannot_be_registered(): void
    {
        [$organization, $actor] = $this->fixtures();
        $this->expectException(AccessDeniedHttpException::class);
        (new AssistantReportAccessService($this->policy))->register('org-'.$organization->id.'/test.pdf', $organization, $actor, [], ['reports']);
    }

    public function test_file_document_requires_the_current_attachment_parent_and_path(): void
    {
        [$organization, $actor, $visible, $private] = $this->fixtures();
        $file = \App\Models\File::create(['organization_id' => $organization->id, 'user_id' => $actor->id,
            'fileable_type' => Project::class, 'fileable_id' => $visible->id, 'name' => 'source.pdf', 'original_name' => 'source.pdf',
            'path' => 'org-'.$organization->id.'/source.pdf', 'disk' => 's3', 'mime_type' => 'application/pdf', 'size' => 1]);
        $document = \App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument::create([
            'organization_id' => $organization->id, 'project_id' => $visible->id, 'file_id' => $file->id,
            'parent_entity_type' => 'project', 'parent_entity_id' => (string) $visible->id,
            'storage_path' => $file->path, 'filename' => 'source.pdf', 'mime_type' => 'application/pdf',
            'checksum' => hash('sha256', 'source'), 'size_bytes' => 1, 'status' => 'ready']);
        $ref = ['source_type' => 'file_document', 'entity_type' => 'assistant_document', 'entity_id' => $document->id];
        $this->assertTrue($this->policy->canReadSource($actor, $organization->id, $ref));
        $file->update(['fileable_id' => $private->id]);
        $this->assertFalse($this->policy->canReadSource($actor, $organization->id, $ref));
        $file->update(['fileable_id' => $visible->id, 'path' => 'org-'.$organization->id.'/replaced.pdf']);
        $this->assertFalse($this->policy->canReadSource($actor, $organization->id, $ref));
        $file->update(['path' => $document->storage_path]);
        $file->delete();
        $this->assertFalse($this->policy->canReadSource($actor, $organization->id, $ref));
    }

    public function test_real_role_revocation_bypasses_warmed_authorization_caches(): void
    {
        [$organization, $actor, $visible] = $this->fixtures();
        $context = \App\Domain\Authorization\Models\AuthorizationContext::getOrganizationContext($organization->id);
        \App\Domain\Authorization\Models\OrganizationCustomRole::create(['organization_id' => $organization->id, 'name' => 'Assistant reader', 'slug' => 'assistant-regression-reader', 'created_by' => $actor->id, 'system_permissions' => ['projects.view', 'ai_assistant.chat'], 'module_permissions' => [], 'interface_access' => [], 'is_active' => true]);
        $assignment = \App\Domain\Authorization\Models\UserRoleAssignment::create([
            'user_id' => $actor->id, 'context_id' => $context->id, 'role_slug' => 'assistant-regression-reader',
            'role_type' => 'custom', 'is_active' => true]);
        $authorization = app(AuthorizationService::class);
        $this->assertTrue($authorization->can($actor, 'projects.view', ['organization_id' => $organization->id]));
        $modules = Mockery::mock(OrganizationEntitlementService::class);
        $modules->shouldReceive('getEffectiveModules')->andReturn(collect([(object) ['slug' => 'project-management'], (object) ['slug' => 'ai-assistant']]));
        $policy = new AssistantDataAccessPolicy($authorization, new UserProjectAccessService, $modules);
        $this->assertTrue($policy->canReadEntity($actor, $organization->id, 'project', $visible->id));
        $this->app->instance(AssistantDataAccessPolicy::class, $policy);
        $checker = new \App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker($authorization);
        $this->assertTrue($checker->canExecuteTool($actor, 'search_projects'));
        DB::table('organization_custom_roles')->where('organization_id', $organization->id)->where('slug', 'assistant-regression-reader')->update(['is_active' => false]);
        $this->assertFalse($authorization->canCurrent($actor, 'projects.view', ['organization_id' => $organization->id]));
        DB::table('organization_custom_roles')->where('organization_id', $organization->id)->where('slug', 'assistant-regression-reader')->update(['is_active' => true]);
        $assignment->update(['is_active' => false]);
        $this->assertFalse($authorization->canCurrent($actor, 'projects.view', ['organization_id' => $organization->id]));
        $this->assertFalse($policy->canReadEntity($actor, $organization->id, 'project', $visible->id));
        $this->app->instance(AssistantDataAccessPolicy::class, $policy);
        $this->assertFalse((new \App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker($authorization))->canExecuteTool($actor, 'search_projects'));
    }

    public function test_cached_reports_deny_missing_malformed_and_revoked_source_provenance(): void
    {
        [$organization, $actor, $visible] = $this->fixtures();
        $base = ['organization_id' => $organization->id, 'project_id' => $visible->id, 'report_date' => today(), 'generated_at' => now(),
            'summary' => [], 'metrics' => [], 'urgent_actions' => [], 'risk_groups' => [], 'finance' => [], 'activity' => [], 'recommendations' => [],
            'required_domains' => ['projects', 'reports']];
        $valid = \App\BusinessModules\Features\AIAssistant\Models\ProjectPulseReport::create($base + ['source_refs' => [['entity_type' => 'project', 'entity_id' => (string) $visible->id]]]);
        \App\BusinessModules\Features\AIAssistant\Models\ProjectPulseReport::create($base + ['source_refs' => [[]]]);
        \App\BusinessModules\Features\AIAssistant\Models\ProjectPulseReport::create($base + ['source_refs' => null]);
        $this->assertSame([$valid->id], $this->policy->entityQuery($actor, $organization->id, 'project_pulse_report')->pluck('project_pulse_reports.id')->all());
        $actor->assignedProjects()->updateExistingPivot($visible->id, ['is_active' => false]);
        $this->assertSame([], $this->policy->entityQuery($actor, $organization->id, 'project_pulse_report')->pluck('project_pulse_reports.id')->all());
    }

    public function test_budget_visibility_does_not_grant_payment_documents_or_payment_rag(): void
    {
        [$organization, $actor, $visible] = $this->fixtures();
        $payment = \App\BusinessModules\Core\Payments\Models\PaymentDocument::create([
            'organization_id' => $organization->id, 'project_id' => $visible->id, 'document_type' => 'invoice',
            'document_number' => 'assistant-payment-'.$organization->id, 'document_date' => today(), 'amount' => '125.50', 'currency' => 'RUB']);
        $ref = ['source_type' => 'payment', 'entity_type' => 'payment_document', 'entity_id' => (string) $payment->id];
        RagSource::create($ref + ['organization_id' => $organization->id, 'project_id' => $visible->id, 'title' => 'Invoice', 'checksum' => hash('sha256', 'invoice'), 'indexed_at' => now()]);
        $this->assertTrue($this->policy->canReadSource($actor, $organization->id, $ref));
        $this->deniedPermissions = ['payments.invoice.view', 'payments.invoice.view_all'];
        $this->assertTrue($this->policy->canReadDomain($actor, $organization->id, 'finance'));
        $this->assertFalse($this->policy->canReadEntity($actor, $organization->id, 'payment_document', $payment->id));
        $this->assertFalse($this->policy->canReadSource($actor, $organization->id, $ref));
        $this->assertFalse(in_array('payment', $this->policy->allowedSourceTypes($actor, $organization->id), true));
        $this->assertSame(0, $this->policy->applyToSources(RagSource::query(), $actor, $organization->id)->count());
    }

    public function test_estimate_attachment_content_requires_financial_permission(): void
    {
        [$organization, $actor, $visible] = $this->fixtures();
        $estimate = \App\Models\Estimate::create(['organization_id' => $organization->id, 'project_id' => $visible->id,
            'number' => 'assistant-estimate-'.$organization->id, 'name' => 'Financial estimate', 'estimate_date' => today()]);
        $file = \App\Models\File::create(['organization_id' => $organization->id, 'user_id' => $actor->id,
            'fileable_type' => \App\Models\Estimate::class, 'fileable_id' => $estimate->id, 'name' => 'estimate.pdf', 'original_name' => 'estimate.pdf',
            'path' => 'org-'.$organization->id.'/estimate.pdf', 'disk' => 's3', 'mime_type' => 'application/pdf', 'size' => 1]);
        $document = \App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument::create([
            'organization_id' => $organization->id, 'project_id' => $visible->id, 'file_id' => $file->id,
            'parent_entity_type' => 'estimate', 'parent_entity_id' => (string) $estimate->id, 'storage_path' => $file->path,
            'filename' => 'estimate.pdf', 'mime_type' => 'application/pdf', 'checksum' => hash('sha256', 'estimate'), 'size_bytes' => 1, 'status' => 'ready']);
        $ref = ['source_type' => 'file_document', 'entity_type' => 'assistant_document', 'entity_id' => (string) $document->id];
        RagSource::create($ref + ['organization_id' => $organization->id, 'project_id' => $visible->id, 'title' => 'Estimate file', 'checksum' => hash('sha256', 'estimate-file'), 'indexed_at' => now()]);
        $this->assertTrue($this->policy->canReadSource($actor, $organization->id, $ref));
        $this->assertSame(1, $this->policy->accessibleFiles($actor, $organization->id)->count());
        $this->assertSame(1, $this->policy->accessibleDocuments($actor, $organization->id)->count());
        $this->deniedPermissions = ['budget-estimates.finance.view'];
        $this->assertTrue($this->policy->canReadEntity($actor, $organization->id, 'estimate', $estimate->id));
        $this->assertFalse($this->policy->canReadEntityContent($actor, $organization->id, 'estimate', $estimate->id));
        $this->assertSame(0, $this->policy->accessibleFiles($actor, $organization->id)->count());
        $this->assertSame(0, $this->policy->accessibleDocuments($actor, $organization->id)->count());
        $this->assertFalse($this->policy->canReadEntity($actor, $organization->id, 'file', $file->id));
        $this->assertFalse($this->policy->canReadSource($actor, $organization->id, $ref));
        $this->assertSame(0, $this->policy->applyToSources(RagSource::query(), $actor, $organization->id)->count());
    }

    public function test_all_registered_measurement_writes_are_classified_as_mutations(): void
    {
        $checker = new \App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
        foreach (['mass_create_measurement_units', 'create_measurement_unit', 'update_measurement_unit', 'delete_measurement_unit',
            'create_schedule_task', 'update_schedule_task_status', 'approve_payment_request', 'send_project_notification'] as $tool) {
            $this->assertTrue($checker->isMutationTool($tool), $tool);
        }
    }

    public function test_existing_financial_answer_is_hidden_after_finance_permission_revocation(): void
    {
        [$organization, $actor, $visible] = $this->fixtures();
        $estimate = \App\Models\Estimate::create(['organization_id' => $organization->id, 'project_id' => $visible->id,
            'number' => 'assistant-history-'.$organization->id, 'name' => 'Estimate history', 'estimate_date' => today()]);
        $ref = ['entity_type' => 'estimate', 'entity_id' => (string) $estimate->id, 'fetched_at' => now()->toISOString()];
        $guard = new \App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceGuard($this->policy);
        $this->assertTrue($guard->canRead($actor, $organization->id, [$ref]));
        $this->deniedPermissions = ['budget-estimates.finance.view'];
        $this->assertTrue($this->policy->canReadEntity($actor, $organization->id, 'estimate', $estimate->id));
        $this->assertFalse($guard->canRead($actor, $organization->id, [$ref]));
        $this->assertFalse($guard->fresh($actor, $organization->id, [$ref]));
        $this->deniedPermissions = ['finance.view', 'finance.view_project_budget', 'payments.dashboard.view', 'payments.invoice.view', 'payments.invoice.view_all'];
        $projectStatus = ['entity_type' => 'project', 'entity_id' => (string) $visible->id, 'content_scope' => 'structured',
            'checked_fields' => ['id', 'status'], 'required_permissions' => ['projects.view'], 'required_domains' => ['projects']];
        $this->assertTrue($guard->canRead($actor, $organization->id, [$projectStatus]));
        unset($projectStatus['content_scope']);
        $this->assertFalse($guard->canRead($actor, $organization->id, [$projectStatus]));
    }

    public function test_procurement_requests_and_orders_inherit_current_site_request_project_acl_before_limit(): void
    {
        [$organization, $actor, $visible, $private] = $this->fixtures();
        $foreignOrganization = Organization::factory()->create();
        $foreignProject = Project::factory()->create(['organization_id' => $foreignOrganization->id, 'is_archived' => false]);
        $requestIds = [];
        foreach ([$private, $visible, $foreignProject] as $index => $project) {
            $siteId = DB::table('site_requests')->insertGetId(['organization_id' => $project->organization_id, 'project_id' => $project->id,
                'user_id' => $actor->id, 'title' => 'Parent-'.$index, 'request_type' => 'material', 'created_at' => now(), 'updated_at' => now()]);
            $requestIds[] = DB::table('purchase_requests')->insertGetId(['organization_id' => $organization->id, 'site_request_id' => $siteId,
                'request_number' => 'ACL-'.$organization->id.'-'.$index, 'created_at' => now(), 'updated_at' => now()]);
        }
        $unlinked = DB::table('purchase_requests')->insertGetId(['organization_id' => $organization->id, 'site_request_id' => null,
            'request_number' => 'ACL-'.$organization->id.'-standalone', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertFalse($this->policy->canReadEntity($actor, $organization->id, 'purchase_request', $requestIds[0]));
        $this->assertTrue($this->policy->canReadEntity($actor, $organization->id, 'purchase_request', $requestIds[1]));
        $this->assertFalse($this->policy->canReadEntity($actor, $organization->id, 'purchase_request', $requestIds[2]));
        $this->assertTrue($this->policy->canReadEntity($actor, $organization->id, 'purchase_request', $unlinked));
        $this->assertSame([$requestIds[1]], $this->policy->entityQuery($actor, $organization->id, 'purchase_request')->orderBy('id')->limit(1)->pluck('id')->all());
        $supplierId = DB::table('suppliers')->insertGetId(['organization_id' => $organization->id, 'name' => 'ACL supplier', 'code' => 'ACL-'.$organization->id, 'created_at' => now(), 'updated_at' => now()]);
        $orderIds = [];
        foreach ($requestIds as $index => $requestId) {
            $orderIds[] = DB::table('purchase_orders')->insertGetId(['organization_id' => $organization->id, 'purchase_request_id' => $requestId,
                'supplier_id' => $supplierId, 'order_number' => 'ACL-ORDER-'.$organization->id.'-'.$index, 'order_date' => today(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->assertFalse($this->policy->canReadEntity($actor, $organization->id, 'purchase_order', $orderIds[0]));
        $this->assertTrue($this->policy->canReadEntity($actor, $organization->id, 'purchase_order', $orderIds[1]));
        $this->assertFalse($this->policy->canReadEntity($actor, $organization->id, 'purchase_order', $orderIds[2]));
        $this->assertSame([$orderIds[1]], $this->policy->entityQuery($actor, $organization->id, 'purchase_order')->orderBy('id')->limit(1)->pluck('id')->all());
        $this->deniedPermissions = ['site_requests.view', 'site-requests.view'];
        $this->assertFalse($this->policy->canReadEntity($actor, $organization->id, 'purchase_request', $requestIds[1]));
        $this->assertTrue($this->policy->canReadEntity($actor, $organization->id, 'purchase_order', $orderIds[1]));
        $this->deniedPermissions = [];
        $this->deniedModuleSlugs = ['site-requests'];
        $this->assertFalse($this->policy->canReadEntity($actor, $organization->id, 'purchase_request', $requestIds[1]));
        $this->deniedModuleSlugs = [];
        $actor->assignedProjects()->updateExistingPivot($visible->id, ['is_active' => false]);
        $this->assertFalse($this->policy->canReadEntity($actor, $organization->id, 'purchase_request', $requestIds[1]));
        $this->assertFalse($this->policy->canReadEntity($actor, $organization->id, 'purchase_order', $orderIds[1]));
    }

    private function fixtures(): array
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create(['current_organization_id' => $organization->id, 'is_active' => true]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'assigned_projects']);
        $visible = Project::factory()->create(['organization_id' => $organization->id, 'is_archived' => false]);
        $private = Project::factory()->create(['organization_id' => $organization->id, 'is_archived' => false]);
        $actor->assignedProjects()->attach($visible->id, ['is_active' => true, 'role' => 'member']);
        return [$organization, $actor, $visible, $private];
    }

    private function index(int $organizationId, int $projectId, string $type, string $entityId, string $title, array $embedding): void
    {
        $source = RagSource::create(['organization_id' => $organizationId, 'project_id' => $projectId, 'source_type' => $type,
            'entity_type' => $type, 'entity_id' => $entityId, 'title' => $title, 'checksum' => hash('sha256', $entityId), 'indexed_at' => now()]);
        DB::table('ai_rag_chunks')->insert(['source_id' => $source->id, 'organization_id' => $organizationId, 'project_id' => $projectId,
            'chunk_index' => 0, 'content' => $title, 'content_hash' => hash('sha256', $title), 'created_at' => now(), 'updated_at' => now(),
            'embedding' => '['.implode(',', RagTestEmbedding::fromLeadingValues($embedding)).']']);
    }
}
