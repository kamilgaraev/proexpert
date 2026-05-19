<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\BusinessModules\Features\HandoverAcceptance\Models\ProjectLocation;
use App\Models\CompletedWork;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\Material;
use App\Models\MeasurementUnit;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WorkType;
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
        Storage::fake('s3');

        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $unit = MeasurementUnit::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Piece',
            'short_name' => 'pcs',
            'type' => 'material',
        ]);
        $material = Material::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Concrete B25',
            'code' => 'MAT-B25',
            'measurement_unit_id' => $unit->id,
            'is_active' => true,
        ]);
        $supplier = Supplier::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Concrete plant',
            'is_active' => true,
        ]);
        $location = ProjectLocation::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'location_type' => 'section',
            'name' => 'Axis A-B',
            'code' => 'A-B',
            'path' => 'Foundation / Axis A-B',
        ]);
        $workType = WorkType::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Reinforcement',
            'code' => 'reinforcement_works',
            'measurement_unit_id' => $unit->id,
            'category' => 'Исполнительная документация',
            'additional_properties' => ['contexts' => ['executive_documentation']],
            'is_active' => true,
        ]);
        $journal = ConstructionJournal::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'name' => 'General work journal',
            'journal_number' => 'GJ-2026-001',
            'start_date' => now()->subWeek()->toDateString(),
            'status' => 'active',
            'created_by_user_id' => $context->user->id,
        ]);
        $journalEntry = ConstructionJournalEntry::query()->create([
            'journal_id' => $journal->id,
            'entry_date' => now()->toDateString(),
            'entry_number' => 3,
            'work_description' => 'Foundation reinforcement',
            'status' => 'approved',
            'created_by_user_id' => $context->user->id,
            'approved_by_user_id' => $context->user->id,
            'approved_at' => now(),
        ]);
        $completedWork = CompletedWork::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'work_type_id' => $workType->id,
            'journal_entry_id' => $journalEntry->id,
            'user_id' => $context->user->id,
            'quantity' => 12.5,
            'completion_date' => now()->toDateString(),
            'status' => 'confirmed',
        ]);

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
            ->post("/api/v1/admin/executive-documentation/sets/{$setId}/documents", [
                'document_type' => 'hidden_work_act',
                'title' => 'Hidden works act for foundation reinforcement',
                'work_type_id' => $workType->id,
                'completed_work_id' => $completedWork->id,
                'journal_entry_id' => $journalEntry->id,
                'document_date' => now()->toDateString(),
                'copies_count' => 2,
                'form_variant' => 'order_344',
                'profile_data' => [
                    'act_number' => 'HWA-2026-001',
                    'presented_works' => 'Foundation reinforcement',
                    'started_at' => now()->subDay()->toDateString(),
                    'finished_at' => now()->toDateString(),
                    'next_works_permission' => 'Concrete works are allowed',
                    'actual_volume' => '12.5 pcs',
                ],
                'metadata' => [
                    'project_location_id' => $location->id,
                    'representative' => 'Customer representative',
                ],
                'participants' => [
                    ['name' => 'Site engineer', 'role' => 'contractor'],
                    ['name' => 'Customer representative', 'role' => 'customer'],
                ],
                'initial_version' => [
                    'file' => $this->fakeDocumentFile('foundation-v1.pdf'),
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
        $unit = MeasurementUnit::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Piece',
            'short_name' => 'pcs',
            'type' => 'material',
        ]);
        $material = Material::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Concrete B25',
            'code' => 'MAT-B25',
            'measurement_unit_id' => $unit->id,
            'is_active' => true,
        ]);
        $supplier = Supplier::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Concrete plant',
            'is_active' => true,
        ]);

        $setResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/executive-documentation/sets', [
                'project_id' => $project->id,
                'title' => 'Material certificates',
            ]);

        $setResponse->assertCreated();
        $setId = (int) $setResponse->json('data.id');
        $location = ProjectLocation::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'location_type' => 'section',
            'name' => 'Axis A-B',
            'code' => 'A-B',
            'path' => 'Foundation / Axis A-B',
        ]);

        $linkOnlyResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/executive-documentation/sets/' . $setId . '/documents', [
                'document_type' => 'incoming_control_document',
                'title' => 'Concrete certificate',
                'document_date' => now()->toDateString(),
                'profile_data' => [
                    'document_number' => 'CERT-2026-001',
                    'received_at' => now()->toDateString(),
                    'checked_at' => now()->toDateString(),
                    'quality_document_kind' => 'certificate',
                    'material_name' => 'Concrete B25',
                    'batch_details' => 'BATCH-42',
                    'quantity' => '10 м3',
                    'quality_document_details' => 'CERT-2026-001',
                    'incoming_control_result' => 'accepted',
                ],
                'initial_version' => [
                    'version_number' => '1.0',
                    'file_url' => 'https://files.example.com/certificate.pdf',
                ],
            ]);

        $linkOnlyResponse->assertStatus(422);
        $linkOnlyResponse->assertJsonValidationErrors(['initial_version.file']);

        $invalidResponse = $this->withHeaders($context->authHeaders())
            ->post('/api/v1/admin/executive-documentation/sets/' . $setId . '/documents', [
                'document_type' => 'incoming_control_document',
                'title' => 'Concrete certificate',
                'document_date' => now()->toDateString(),
                'initial_version' => [
                    'version_number' => '1.0',
                    'file' => UploadedFile::fake()->createWithContent('certificate.pdf', str_repeat('a', 1024)),
                ],
            ]);

        $invalidResponse->assertStatus(422);
        $invalidResponse->assertJsonValidationErrors([
            'profile_data.document_number',
            'profile_data.received_at',
            'profile_data.checked_at',
            'profile_data.quality_document_kind',
            'profile_data.material_name',
            'profile_data.batch_details',
            'profile_data.quantity',
            'profile_data.quality_document_details',
            'profile_data.incoming_control_result',
        ]);

        $autoDateResponse = $this->withHeaders($context->authHeaders())
            ->post('/api/v1/admin/executive-documentation/sets/' . $setId . '/documents', [
                'document_type' => 'incoming_control_document',
                'title' => 'Concrete certificate',
                'profile_data' => [
                    'document_number' => 'CERT-2026-001',
                    'received_at' => now()->toDateString(),
                    'checked_at' => now()->toDateString(),
                    'quality_document_kind' => 'certificate',
                    'material_name' => 'Concrete B25',
                    'batch_details' => 'BATCH-42',
                    'quantity' => '10 м3',
                    'quality_document_details' => 'CERT-2026-001',
                    'incoming_control_result' => 'accepted',
                ],
                'initial_version' => [
                    'version_number' => '1.0',
                    'file' => UploadedFile::fake()->createWithContent('certificate.pdf', str_repeat('a', 1024)),
                ],
            ]);

        $autoDateResponse->assertCreated();
        $autoDateResponse->assertJsonPath('data.document_date', now()->toDateString());

        $response = $this->withHeaders($context->authHeaders())
            ->post('/api/v1/admin/executive-documentation/sets/' . $setId . '/documents', [
                'document_type' => 'incoming_control_document',
                'title' => 'Concrete certificate',
                'section_name' => 'Axis A-B',
                'document_date' => now()->toDateString(),
                'copies_count' => 1,
                'profile_data' => [
                    'document_number' => 'CERT-2026-001',
                    'received_at' => now()->toDateString(),
                    'checked_at' => now()->toDateString(),
                    'quality_document_kind' => 'certificate',
                    'material_name' => 'Concrete B25',
                    'manufacturer' => 'Concrete plant',
                    'supplier' => 'Concrete plant',
                    'batch_details' => 'BATCH-42',
                    'quantity' => '10 м3',
                    'quality_document_details' => 'CERT-2026-001 от ' . now()->toDateString(),
                    'incoming_control_result' => 'accepted',
                ],
                'metadata' => [
                    'project_location_id' => $location->id,
                    'material_id' => $material->id,
                    'supplier_id' => $supplier->id,
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
            'document_type' => 'incoming_control_document',
        ]);
    }

    public function test_work_journal_must_include_required_profile_fields(): void
    {
        Storage::fake('s3');

        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreignProject = Project::factory()->create(['organization_id' => $foreignContext->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $setResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/executive-documentation/sets', [
                'project_id' => $project->id,
                'title' => 'Work log extracts',
            ]);

        $setResponse->assertCreated();
        $setId = (int) $setResponse->json('data.id');

        $location = ProjectLocation::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'location_type' => 'section',
            'name' => 'Axis A-B',
            'code' => 'A-B',
            'path' => 'Foundation / Axis A-B',
        ]);

        $ownJournal = ConstructionJournal::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'name' => 'General work journal',
            'journal_number' => 'GJ-2026-001',
            'start_date' => now()->subWeek()->toDateString(),
            'status' => 'active',
            'created_by_user_id' => $context->user->id,
        ]);
        $foreignJournal = ConstructionJournal::query()->create([
            'organization_id' => $foreignContext->organization->id,
            'project_id' => $foreignProject->id,
            'name' => 'Foreign work journal',
            'journal_number' => 'GJ-FOREIGN',
            'start_date' => now()->subWeek()->toDateString(),
            'status' => 'active',
            'created_by_user_id' => $foreignContext->user->id,
        ]);
        $ownEntry = ConstructionJournalEntry::query()->create([
            'journal_id' => $ownJournal->id,
            'entry_date' => now()->toDateString(),
            'entry_number' => 3,
            'work_description' => 'Concrete slab works',
            'status' => 'approved',
            'created_by_user_id' => $context->user->id,
            'approved_by_user_id' => $context->user->id,
            'approved_at' => now(),
        ]);
        $foreignEntry = ConstructionJournalEntry::query()->create([
            'journal_id' => $foreignJournal->id,
            'entry_date' => now()->toDateString(),
            'entry_number' => 9,
            'work_description' => 'Foreign works',
            'status' => 'approved',
            'created_by_user_id' => $foreignContext->user->id,
            'approved_by_user_id' => $foreignContext->user->id,
            'approved_at' => now(),
        ]);

        $foreignJournalResponse = $this->withHeaders($context->authHeaders())
            ->post("/api/v1/admin/executive-documentation/sets/{$setId}/documents", [
                'document_type' => 'work_journal',
                'title' => 'Work journal package',
                'document_date' => now()->toDateString(),
                'profile_data' => [
                    'journal_kind' => 'general',
                    'journal_number' => 'GJ-2026-001',
                    'opened_at' => now()->subWeek()->toDateString(),
                    'journal_organization' => 'General contractor',
                    'responsible_person' => 'Site engineer',
                    'journal_period' => '01.05.2026-07.05.2026',
                    'journal_status' => 'active',
                ],
                'relations' => [
                    [
                        'relation_type' => 'journal_entries',
                        'target_type' => 'journal_entry',
                        'target_id' => $foreignEntry->id,
                    ],
                ],
                'initial_version' => [
                    'file' => $this->fakeDocumentFile('work-log-foreign-journal.pdf'),
                    'version_number' => '1.0',
                ],
            ]);

        $foreignJournalResponse->assertStatus(422);
        $foreignJournalResponse->assertJsonValidationErrors(['relations.0.target_id']);

        $foreignEntryResponse = $this->withHeaders($context->authHeaders())
            ->post("/api/v1/admin/executive-documentation/sets/{$setId}/documents", [
                'document_type' => 'work_journal',
                'title' => 'Work journal package',
                'document_date' => now()->toDateString(),
                'profile_data' => [],
                'initial_version' => [
                    'file' => $this->fakeDocumentFile('work-log-foreign-entry.pdf'),
                    'version_number' => '1.0',
                ],
            ]);

        $foreignEntryResponse->assertStatus(422);
        $foreignEntryResponse->assertJsonValidationErrors([
            'profile_data.journal_kind',
            'profile_data.journal_number',
            'profile_data.opened_at',
            'profile_data.journal_organization',
            'profile_data.responsible_person',
            'profile_data.journal_period',
            'profile_data.journal_status',
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->post("/api/v1/admin/executive-documentation/sets/{$setId}/documents", [
                'document_type' => 'work_journal',
                'title' => 'Work journal package',
                'document_date' => now()->toDateString(),
                'profile_data' => [
                    'journal_kind' => 'general',
                    'journal_number' => 'GJ-2026-001',
                    'opened_at' => now()->subWeek()->toDateString(),
                    'journal_organization' => 'General contractor',
                    'responsible_person' => 'Site engineer',
                    'journal_period' => '01.05.2026-07.05.2026',
                    'journal_status' => 'active',
                ],
                'relations' => [
                    [
                        'relation_type' => 'journal_entries',
                        'target_type' => 'journal_entry',
                        'target_id' => $ownEntry->id,
                        'label' => 'Concrete slab works',
                    ],
                ],
                'initial_version' => [
                    'file' => $this->fakeDocumentFile('work-log.pdf'),
                    'version_number' => '1.0',
                ],
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.document_type', 'work_journal');
        $response->assertJsonPath('data.profile_data.journal_number', 'GJ-2026-001');
        $response->assertJsonPath('data.relations.0.target_type', 'journal_entry');
        $response->assertJsonPath('data.relations.0.target_id', $ownEntry->id);
    }

    public function test_incoming_control_document_must_include_required_profile_fields(): void
    {
        Storage::fake('s3');

        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $unit = MeasurementUnit::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Piece',
            'short_name' => 'pcs',
            'type' => 'material',
        ]);
        $foreignUnit = MeasurementUnit::query()->create([
            'organization_id' => $foreignContext->organization->id,
            'name' => 'Foreign piece',
            'short_name' => 'fpcs',
            'type' => 'material',
        ]);
        $material = Material::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Concrete B25',
            'code' => 'MAT-B25',
            'measurement_unit_id' => $unit->id,
            'is_active' => true,
        ]);
        $foreignMaterial = Material::query()->create([
            'organization_id' => $foreignContext->organization->id,
            'name' => 'Foreign concrete',
            'code' => 'MAT-FOREIGN',
            'measurement_unit_id' => $foreignUnit->id,
            'is_active' => true,
        ]);
        $supplier = Supplier::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Concrete plant',
            'is_active' => true,
        ]);
        $foreignSupplier = Supplier::query()->create([
            'organization_id' => $foreignContext->organization->id,
            'name' => 'Foreign plant',
            'is_active' => true,
        ]);

        $setResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/executive-documentation/sets', [
                'project_id' => $project->id,
                'title' => 'Material certificates',
            ]);

        $setResponse->assertCreated();
        $setId = (int) $setResponse->json('data.id');

        $foreignMaterialResponse = $this->withHeaders($context->authHeaders())
            ->post("/api/v1/admin/executive-documentation/sets/{$setId}/documents", [
                'document_type' => 'incoming_control_document',
                'title' => 'Concrete certificate',
                'document_date' => now()->toDateString(),
                'profile_data' => [
                    'document_number' => 'CERT-2026-001',
                    'received_at' => now()->toDateString(),
                    'checked_at' => now()->toDateString(),
                    'quality_document_kind' => 'certificate',
                    'material_name' => 'Concrete B25',
                    'supplier' => 'Concrete plant',
                    'batch_details' => 'BATCH-42',
                    'quantity' => '10 м3',
                    'quality_document_details' => 'CERT-2026-001',
                    'incoming_control_result' => 'accepted',
                ],
                'relations' => [
                    [
                        'relation_type' => 'material_reference',
                        'target_type' => 'material',
                        'target_id' => $foreignMaterial->id,
                    ],
                ],
                'initial_version' => [
                    'file' => $this->fakeDocumentFile('certificate-foreign-material.pdf'),
                    'version_number' => '1.0',
                ],
            ]);

        $foreignMaterialResponse->assertStatus(422);
        $foreignMaterialResponse->assertJsonValidationErrors(['relations.0.target_id']);

        $foreignSupplierResponse = $this->withHeaders($context->authHeaders())
            ->post("/api/v1/admin/executive-documentation/sets/{$setId}/documents", [
                'document_type' => 'incoming_control_document',
                'title' => 'Concrete certificate',
                'document_date' => now()->toDateString(),
                'profile_data' => [
                    'document_number' => 'CERT-2026-001',
                    'received_at' => now()->toDateString(),
                    'checked_at' => now()->toDateString(),
                    'quality_document_kind' => 'certificate',
                    'material_name' => 'Concrete B25',
                    'supplier' => 'Foreign plant',
                    'batch_details' => 'BATCH-42',
                    'quantity' => '10 м3',
                    'quality_document_details' => 'CERT-2026-001',
                    'incoming_control_result' => 'accepted',
                ],
                'relations' => [
                    [
                        'relation_type' => 'supplier_document',
                        'target_type' => 'supplier',
                        'target_id' => $foreignSupplier->id,
                    ],
                ],
                'initial_version' => [
                    'file' => $this->fakeDocumentFile('certificate-foreign-supplier.pdf'),
                    'version_number' => '1.0',
                ],
            ]);

        $foreignSupplierResponse->assertStatus(422);
        $foreignSupplierResponse->assertJsonValidationErrors(['relations.0.target_id']);

        $response = $this->withHeaders($context->authHeaders())
            ->post("/api/v1/admin/executive-documentation/sets/{$setId}/documents", [
                'document_type' => 'incoming_control_document',
                'title' => 'Concrete certificate',
                'document_date' => now()->toDateString(),
                'profile_data' => [
                    'document_number' => 'CERT-2026-001',
                    'received_at' => now()->toDateString(),
                    'checked_at' => now()->toDateString(),
                    'quality_document_kind' => 'certificate',
                    'material_name' => 'Concrete B25',
                    'supplier' => 'Concrete plant',
                    'batch_details' => 'BATCH-42',
                    'quantity' => '10 м3',
                    'quality_document_details' => 'CERT-2026-001',
                    'incoming_control_result' => 'accepted',
                ],
                'relations' => [
                    [
                        'relation_type' => 'material_reference',
                        'target_type' => 'material',
                        'target_id' => $material->id,
                        'label' => 'Concrete B25',
                    ],
                ],
                'initial_version' => [
                    'file' => $this->fakeDocumentFile('certificate.pdf'),
                    'version_number' => '1.0',
                ],
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.document_type', 'incoming_control_document');
        $response->assertJsonPath('data.profile_data.material_name', 'Concrete B25');
        $response->assertJsonPath('data.profile_data.supplier', 'Concrete plant');
        $response->assertJsonPath('data.relations.0.target_type', 'material');
        $response->assertJsonPath('data.relations.0.target_id', $material->id);
    }

    private function fakeDocumentFile(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, str_repeat('a', 1024));
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
