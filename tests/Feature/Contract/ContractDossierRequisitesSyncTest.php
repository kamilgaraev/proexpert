<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\DTOs\Contract\ContractDTO;
use App\Enums\Contract\ContractSideTypeEnum;
use App\Enums\Contract\ContractStatusEnum;
use App\Enums\Contract\GpCalculationTypeEnum;
use App\Models\Contractor;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Contract\ContractSideMutationService;
use App\Services\LegalArchive\Audit\LegalDocumentAudit;
use Mockery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class ContractDossierRequisitesSyncTest extends TestCase
{
    #[DataProvider('documentStates')]
    public function test_contract_date_sync_respects_document_ownership_and_editability(array $attributes, string $expectedDate): void
    {
        [$organization, $project, $contractor, $service, $contract, $document] = $this->fixture($attributes);

        $service->update($contract->id, $organization->id, $this->input($project->id, $contractor->id, '2026-09-04'));

        self::assertSame('2026-09-04', $contract->fresh()->date->toDateString());
        self::assertSame($expectedDate, $document->fresh()->document_date->toDateString());
    }

    public function test_number_and_period_follow_contract_and_can_be_cleared(): void
    {
        [$organization, $project, $contractor, $service, $contract, $document] = $this->fixture();
        $originalLock = (int) $document->lock_version;

        $service->update($contract->id, $organization->id, $this->input(
            $project->id, $contractor->id, '2026-09-03', 'ДА-2', '2026-09-04', '2026-09-30',
        ));

        $document->refresh();
        self::assertSame('ДА-2', $document->document_number);
        self::assertSame('2026-09-04', $document->effective_from->toDateString());
        self::assertSame('2026-09-30', $document->effective_until->toDateString());
        self::assertSame($originalLock + 1, (int) $document->lock_version);
        self::assertSame('Учебный договор', $document->title);

        $service->update($contract->id, $organization->id, $this->input($project->id, $contractor->id, '2026-09-03', 'ДА-2'));

        $document->refresh();
        self::assertNull($document->effective_from);
        self::assertNull($document->effective_until);
        self::assertSame($originalLock + 2, (int) $document->lock_version);
    }

    public function test_unchanged_requisites_do_not_increment_document_version(): void
    {
        [$organization, $project, $contractor, $service, $contract, $document] = $this->fixture();
        $originalLock = (int) $document->lock_version;

        $service->update($contract->id, $organization->id, $this->input($project->id, $contractor->id, '2026-09-03'));

        self::assertSame($originalLock, (int) $document->fresh()->lock_version);
    }

    public function test_manual_number_is_preserved_while_shared_date_is_updated(): void
    {
        [$organization, $project, $contractor, $service, $contract, $document] = $this->fixture(['document_number' => 'Оригинал-7']);

        $service->update($contract->id, $organization->id, $this->input($project->id, $contractor->id, '2026-09-04', 'ДА-2'));

        $document->refresh();
        self::assertSame('Оригинал-7', $document->document_number);
        self::assertSame('2026-09-04', $document->document_date->toDateString());
        self::assertSame('ДА-2', $contract->fresh()->number);
    }

    public function test_document_audit_failure_rolls_back_both_requisites(): void
    {
        [$organization, $project, $contractor, $service, $contract, $document] = $this->fixture([], true);
        $originalLock = (int) $document->lock_version;

        try {
            $service->update($contract->id, $organization->id, $this->input($project->id, $contractor->id, '2026-09-04'));
            self::fail('Expected document audit failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('document audit unavailable', $exception->getMessage());
        }

        self::assertSame('2026-09-03', $contract->fresh()->date->toDateString());
        self::assertSame('2026-09-03', $document->fresh()->document_date->toDateString());
        self::assertSame($originalLock, (int) $document->fresh()->lock_version);
    }

    #[DataProvider('activeProcesses')]
    public function test_active_legal_process_preserves_document_requisites(string $process): void
    {
        [$organization, $project, $contractor, $service, $contract, $document] = $this->fixture();
        $this->activeProcess($document, $process);
        $originalLock = (int) $document->lock_version;

        $service->update($contract->id, $organization->id, $this->input($project->id, $contractor->id, '2026-09-04'));

        self::assertSame('2026-09-04', $contract->fresh()->date->toDateString());
        self::assertSame('2026-09-03', $document->fresh()->document_date->toDateString());
        self::assertSame($originalLock, (int) $document->fresh()->lock_version);
    }

    public static function activeProcesses(): array
    {
        return [['editor'], ['workflow'], ['signature']];
    }

    private function activeProcess(LegalArchiveDocument $document, string $process): void
    {
        $organizationId = (int) $document->organization_id;
        $user = User::factory()->create();
        $hash = str_repeat('a', 64);
        $timestamps = ['created_at' => now(), 'updated_at' => now()];
        $fileId = DB::table('legal_archive_document_files')->insertGetId([
            'document_id' => $document->id, 'organization_id' => $organizationId,
            'role' => 'main', 'title' => 'Учебный договор', ...$timestamps,
        ]);
        $versionId = DB::table('legal_archive_document_versions')->insertGetId([
            'document_id' => $document->id, 'organization_id' => $organizationId,
            'document_file_id' => $fileId, 'version_number' => '1', 'is_current' => true,
            'status' => 'uploaded', 'processing_status' => 'ready',
            'file_path' => "org-{$organizationId}/legal-archive/test.docx", 'original_filename' => 'test.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'size_bytes' => 10, 'content_hash' => $hash, 'uploaded_at' => now(), ...$timestamps,
        ]);
        DB::table('legal_archive_document_files')->where('id', $fileId)->update(['current_version_id' => $versionId]);
        $document->update(['current_primary_version_id' => $versionId]);

        if ($process === 'editor') {
            DB::table('legal_document_editor_sessions')->insert([
                'id' => (string) Str::uuid(), 'organization_id' => $organizationId,
                'document_id' => $document->id, 'source_version_id' => $versionId,
                'document_file_id' => $fileId, 'opened_by_user_id' => $user->id,
                'provider' => 'test', 'mode' => 'edit', 'status' => 'active', 'generation' => 1,
                'document_key' => (string) Str::uuid(), 'source_content_hash' => $hash,
                'expires_at' => now()->addHour(), ...$timestamps,
            ]);
        } elseif ($process === 'workflow') {
            $templateId = DB::table('legal_workflow_templates')->insertGetId([
                'organization_id' => $organizationId, 'code' => 'test', 'version' => 1,
                'name' => 'Учебное согласование', 'definition_hash' => $hash,
                'created_by_user_id' => $user->id, ...$timestamps,
            ]);
            DB::table('legal_workflow_instances')->insert([
                'organization_id' => $organizationId, 'document_id' => $document->id,
                'document_version_id' => $versionId, 'document_content_hash' => $hash,
                'template_id' => $templateId, 'template_version' => 1, 'template_definition_hash' => $hash,
                'template_snapshot' => '{}', 'snapshot_hash' => $hash, 'client_request_hash' => $hash,
                'request_hash' => $hash, 'idempotency_key' => (string) Str::uuid(), 'status' => 'in_progress',
                'submitted_by_user_id' => $user->id, 'submitted_at' => now(), ...$timestamps,
            ]);
        } else {
            DB::table('legal_signature_requests')->insert([
                'organization_id' => $organizationId, 'document_id' => $document->id,
                'document_version_id' => $versionId, 'method' => 'provider_electronic', 'provider' => 'test',
                'status' => 'pending', 'signed_content_hash' => $hash,
                'signers' => json_encode([['user_id' => $user->id]], JSON_THROW_ON_ERROR),
                'signer_snapshot_hash' => $hash, 'profile_code' => 'contract.lease', 'profile_lock_version' => 0,
                'allowed_signature_kinds' => '["detached_cades"]', 'required_signature_kinds' => '[]',
                'allowed_signature_formats' => '["p7s"]', 'requirement_snapshot_hash' => $hash,
                'requirement_group_key' => $hash, 'correlation_id' => $hash,
                'idempotency_key' => (string) Str::uuid(), 'request_hash' => $hash,
                'requested_by_user_id' => $user->id, 'requested_at' => now(), ...$timestamps,
            ]);
        }
    }

    private function fixture(array $attributes = [], bool $failDocumentAudit = false): array
    {
        $audit = Mockery::mock(LegalDocumentAudit::class);
        $audit->shouldReceive('recordContractForActorId')->andReturnNull();
        if ($failDocumentAudit) {
            $audit->shouldReceive('recordForActorId')->andThrow(new RuntimeException('document audit unavailable'));
        } else {
            $audit->shouldReceive('recordForActorId')->andReturnNull();
        }
        $this->app->instance(LegalDocumentAudit::class, $audit);
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $contractor = Contractor::query()->create(['organization_id' => $organization->id, 'name' => 'Учебный арендодатель']);
        $service = app(ContractSideMutationService::class);
        $contract = $service->create($organization->id, $this->input($project->id, $contractor->id, '2026-09-03'));
        $document = LegalArchiveDocument::query()->create([
            'organization_id' => $organization->id,
            'primary_project_id' => $project->id,
            'title' => 'Учебный договор',
            'document_number' => $contract->number,
            'document_type' => 'contract',
            'type_profile_code' => 'contract.lease',
            'document_date' => '2026-09-03',
            'source_type' => 'contract',
            'source_id' => (string) $contract->id,
            'source_idempotency_key' => 'requisites-sync-test',
            'status' => 'draft',
            'lifecycle_status' => 'draft',
            'approval_status' => 'not_started',
            ...$attributes,
        ]);
        $contract->update([
            'legal_archive_document_id' => $document->id,
            'dossier_creation_key' => 'requisites-sync-test',
        ]);

        return [$organization, $project, $contractor, $service, $contract, $document];
    }

    public static function documentStates(): array
    {
        return [
            'generated draft follows contract' => [[], '2026-09-04'],
            'manual document date is preserved' => [['document_date' => '2026-09-02'], '2026-09-02'],
            'approved document is frozen' => [['approval_status' => 'approved'], '2026-09-03'],
            'signed document is frozen' => [['lifecycle_status' => 'signed'], '2026-09-03'],
            'archived document is frozen' => [['lifecycle_status' => 'archived'], '2026-09-03'],
            'separately created dossier is unchanged' => [['source_idempotency_key' => 'another-creation'], '2026-09-03'],
        ];
    }

    private function input(int $projectId, int $contractorId, string $date, string $number = 'ДА-1', ?string $start = null, ?string $end = null): ContractDTO
    {
        return new ContractDTO(
            project_id: $projectId,
            contractor_id: $contractorId,
            parent_contract_id: null,
            number: $number,
            date: $date,
            subject: 'Аренда бытовки',
            work_type_category: null,
            payment_terms: null,
            base_amount: 10000,
            total_amount: 10000,
            gp_percentage: null,
            gp_calculation_type: GpCalculationTypeEnum::PERCENTAGE,
            gp_coefficient: null,
            warranty_retention_calculation_type: null,
            warranty_retention_percentage: null,
            warranty_retention_coefficient: null,
            subcontract_amount: null,
            planned_advance_amount: null,
            actual_advance_amount: null,
            status: ContractStatusEnum::DRAFT,
            start_date: $start,
            end_date: $end,
            notes: null,
            contract_side_type: ContractSideTypeEnum::GENERAL_CONTRACTOR_TO_CONTRACTOR,
        );
    }
}
