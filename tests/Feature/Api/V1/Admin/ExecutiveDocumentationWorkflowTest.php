<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class ExecutiveDocumentationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_prepare_review_approve_and_transmit_executive_document_set(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $setResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/executive-documentation/sets', [
                'project_id' => $project->id,
                'title' => 'Foundation executive package',
                'stage_name' => 'Foundation',
                'zone_name' => 'Axis A-B',
                'planned_transmittal_date' => now()->addDays(7)->toDateString(),
            ]);

        $setResponse->assertCreated();
        $setResponse->assertJsonPath('data.status', 'draft');
        $setResponse->assertJsonPath('data.project.id', $project->id);
        $setResponse->assertJsonPath('data.workflow_summary.status', 'draft');
        $setResponse->assertJsonPath('data.workflow_summary.available_actions', []);
        $setResponse->assertJsonPath('data.workflow_summary.problem_flags.0.key', 'no_documents');

        $setId = (int) $setResponse->json('data.id');

        $documentResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/executive-documentation/sets/{$setId}/documents", [
                'document_type' => 'hidden_work_act',
                'title' => 'Hidden works act for foundation reinforcement',
                'work_type_name' => 'Reinforcement',
                'section_name' => 'Axis A-B',
                'inspection_date' => now()->toDateString(),
                'metadata' => [
                    'act_number' => 'HWA-2026-001',
                    'representative' => 'Customer representative',
                ],
                'participants' => [
                    ['name' => 'Site engineer', 'role' => 'contractor'],
                    ['name' => 'Customer representative', 'role' => 'customer'],
                ],
                'initial_version' => [
                    'file_url' => 's3://org-' . $context->organization->id . '/executive/foundation-v1.pdf',
                    'version_number' => '1.0',
                    'uploaded_at' => now()->toISOString(),
                ],
            ]);

        $documentResponse->assertCreated();
        $documentResponse->assertJsonPath('data.status', 'draft');
        $documentResponse->assertJsonPath('data.document_type', 'hidden_work_act');
        $documentResponse->assertJsonPath('data.versions.0.version_number', '1.0');
        $documentResponse->assertJsonPath('data.workflow_summary.status', 'draft');

        $documentId = (int) $documentResponse->json('data.id');
        $versionId = (int) $documentResponse->json('data.versions.0.id');

        $submitResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/executive-documentation/documents/{$documentId}/submit", [
                'comment' => 'Ready for review',
            ]);

        $submitResponse->assertOk();
        $submitResponse->assertJsonPath('data.status', 'under_review');

        $remarkResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/executive-documentation/documents/{$documentId}/remarks", [
                'body' => 'Add concrete batch reference',
                'severity' => 'major',
            ]);

        $remarkResponse->assertCreated();
        $remarkResponse->assertJsonPath('data.status', 'open');

        $approveWithOpenRemarkResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/executive-documentation/documents/{$documentId}/approve", [
                'comment' => 'Approved',
            ]);

        $approveWithOpenRemarkResponse->assertStatus(422);

        $remarkId = (int) $remarkResponse->json('data.id');

        $resolveRemarkResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/executive-documentation/remarks/{$remarkId}/resolve", [
                'resolution_comment' => 'Batch reference added in version 1.1',
            ]);

        $resolveRemarkResponse->assertOk();
        $resolveRemarkResponse->assertJsonPath('data.status', 'resolved');

        $approveResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/executive-documentation/documents/{$documentId}/approve", [
                'comment' => 'Approved after remark resolution',
            ]);

        $approveResponse->assertOk();
        $approveResponse->assertJsonPath('data.status', 'approved');

        $transmitResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/executive-documentation/sets/{$setId}/transmit", [
                'transmittal_number' => 'TR-2026-0001',
                'comment' => 'Transmitted to customer',
            ]);

        $transmitResponse->assertOk();
        $transmitResponse->assertJsonPath('data.status', 'transmitted');
        $transmitResponse->assertJsonPath('data.transmittal.transmittal_number', 'TR-2026-0001');

        $deleteVersionResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/executive-documentation/documents/{$documentId}/versions/{$versionId}");

        $deleteVersionResponse->assertStatus(422);

        $customerResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/customer/executive-documentation/sets');

        $customerResponse->assertOk();
        $ids = collect($customerResponse->json('data'))->pluck('id')->all();
        $this->assertContains($setId, $ids);

        $customerRemarkResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/customer/executive-documentation/documents/{$documentId}/remarks", [
                'body' => 'Customer asks to attach the concrete batch passport',
                'severity' => 'major',
            ]);

        $customerRemarkResponse->assertCreated();
        $customerRemarkResponse->assertJsonPath('data.status', 'open');

        $acknowledgeResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/customer/executive-documentation/sets/{$setId}/acknowledge", [
                'comment' => 'Received for customer archive',
            ]);

        $acknowledgeResponse->assertOk();
        $acknowledgeResponse->assertJsonPath('data.transmittal.acknowledged', true);
    }

    public function test_executive_documentation_rejects_foreign_project_and_hides_untransmitted_sets_from_customer(): void
    {
        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreignProject = Project::factory()->create(['organization_id' => $foreignContext->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $foreignProjectResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/executive-documentation/sets', [
                'project_id' => $foreignProject->id,
                'title' => 'Foreign package',
            ]);

        $foreignProjectResponse->assertStatus(422);

        $ownSetResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/executive-documentation/sets', [
                'project_id' => $project->id,
                'title' => 'Not transmitted package',
            ]);

        $ownSetResponse->assertCreated();
        $ownSetId = (int) $ownSetResponse->json('data.id');

        $customerResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/customer/executive-documentation/sets');

        $customerResponse->assertOk();
        $ids = collect($customerResponse->json('data'))->pluck('id')->all();
        $this->assertNotContains($ownSetId, $ids);
    }

    public function test_document_file_is_uploaded_to_organization_s3_path_and_type_fields_are_required(): void
    {
        Storage::fake('s3');

        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $setResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/executive-documentation/sets', [
                'project_id' => $project->id,
                'title' => 'Material certificates',
            ]);

        $setResponse->assertCreated();
        $setId = (int) $setResponse->json('data.id');

        $invalidResponse = $this->withHeaders($context->authHeaders())
            ->post('/api/v1/admin/executive-documentation/sets/' . $setId . '/documents', [
                'document_type' => 'material_certificate',
                'title' => 'Concrete certificate',
                'initial_version' => [
                    'version_number' => '1.0',
                    'file' => UploadedFile::fake()->createWithContent('certificate.pdf', str_repeat('a', 1024)),
                ],
            ]);

        $invalidResponse->assertStatus(422);
        $invalidResponse->assertJsonValidationErrors([
            'metadata.material_name',
            'metadata.batch_number',
            'metadata.supplier_name',
            'metadata.certificate_number',
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->post('/api/v1/admin/executive-documentation/sets/' . $setId . '/documents', [
                'document_type' => 'material_certificate',
                'title' => 'Concrete certificate',
                'section_name' => 'Axis A-B',
                'metadata' => [
                    'material_name' => 'Concrete B25',
                    'batch_number' => 'BATCH-42',
                    'supplier_name' => 'Concrete plant',
                    'certificate_number' => 'CERT-2026-001',
                ],
                'initial_version' => [
                    'version_number' => '1.0',
                    'file' => UploadedFile::fake()->createWithContent('certificate.pdf', str_repeat('a', 1024)),
                ],
            ]);

        $response->assertCreated();
        $path = (string) $response->json('data.versions.0.file_url');

        $this->assertStringStartsWith("org-{$context->organization->id}/executive-documentation/", $path);
        $this->assertStringEndsWith('.pdf', $path);
        Storage::disk('s3')->assertExists($path);
        $this->assertDatabaseHas('executive_documents', [
            'id' => $response->json('data.id'),
            'organization_id' => $context->organization->id,
            'document_type' => 'material_certificate',
        ]);
    }

    private function allowModuleAccess(): void
    {
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')->andReturnUsing(
                static fn (int $organizationId, string $moduleSlug): bool => in_array($moduleSlug, [
                    'executive-documentation',
                    'project-management',
                    'contract-management',
                    'file-management',
                    'report-templates',
                ], true)
            );
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
}
