<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Services\HrDocumentService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WorkforceHrPaperDocumentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_records_paper_scan_with_version_date_and_original_archive_location(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowDocuments();
        $employee = $this->employee((int) $context->organization->id);
        $original = $this->file($employee, 'original', (int) $context->user->id);
        $scan = $this->file($employee, 'scan', (int) $context->user->id);
        $service = app(HrDocumentService::class);
        $document = $service->register((int) $context->organization->id, (int) $employee->id, $this->registry((int) $original->id), $context->user);
        $signed = $service->paperEvidence((int) $context->organization->id, (int) $employee->id, (int) $document->id, [
            'evidence_file_id' => $scan->id, 'expected_revision' => 1, 'signed_on' => '2026-10-02', 'paper_original_location' => 'Archive HR / case 1 / document 1',
        ], $context->user);

        $this->assertSame('paper_signed', $signed->evidence_kind);
        $this->assertSame((int) $scan->id, (int) $signed->evidence_file_id);
        $this->assertSame('2026-10-02', $signed->paper_evidence_metadata['signed_on']);
        $this->assertSame(1, $signed->paper_evidence_metadata['matching_revision']);
        $this->assertSame((int) $context->user->id, $signed->paper_evidence_metadata['recorded_by']);
        $this->assertSame('operator_registered_paper_evidence', $signed->paper_evidence_metadata['declaration']);
        $this->assertFalse($signed->retention_policy_metadata['disposal_allowed']);
    }

    public function test_scan_of_a_different_revision_does_not_change_unsigned_registry_entry(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowDocuments();
        $employee = $this->employee((int) $context->organization->id);
        $original = $this->file($employee, 'original', (int) $context->user->id);
        $scan = $this->file($employee, 'scan', (int) $context->user->id);
        $service = app(HrDocumentService::class);
        $document = $service->register((int) $context->organization->id, (int) $employee->id, $this->registry((int) $original->id), $context->user);
        try {
            $service->paperEvidence((int) $context->organization->id, (int) $employee->id, (int) $document->id, [
                'evidence_file_id' => $scan->id, 'expected_revision' => 2, 'signed_on' => '2026-10-02', 'paper_original_location' => 'Archive HR / case 1',
            ], $context->user);
            $this->fail('A scan of a different revision was accepted');
        } catch (\DomainException) {
            $this->assertSame('unsigned', $document->refresh()->evidence_kind);
            $this->assertNull($document->paper_evidence_metadata);
        }
    }

    public function test_paper_evidence_from_another_employee_cannot_be_registered(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowDocuments();
        $employee = $this->employee((int) $context->organization->id);
        $other = $this->employee((int) $context->organization->id, 'HR-OTHER');
        $original = $this->file($employee, 'original', (int) $context->user->id);
        $foreignScan = $this->file($other, 'scan', (int) $context->user->id);
        $service = app(HrDocumentService::class);
        $document = $service->register((int) $context->organization->id, (int) $employee->id, $this->registry((int) $original->id), $context->user);
        $this->expectException(\DomainException::class);
        $service->paperEvidence((int) $context->organization->id, (int) $employee->id, (int) $document->id, [
            'evidence_file_id' => $foreignScan->id, 'expected_revision' => 1, 'signed_on' => '2026-10-02', 'paper_original_location' => 'Archive HR / case 1',
        ], $context->user);
    }

    private function registry(int $fileId): array
    {
        return ['document_type' => 'manual_classification_test', 'title' => 'Paper HR document', 'document_date' => '2026-10-01',
            'file_id' => $fileId, 'retention_class' => 'unclassified_hold'];
    }

    private function employee(int $organizationId, string $number = 'HR-DOC-1'): WorkforceEmployee
    {
        return WorkforceEmployee::create(['organization_id' => $organizationId, 'personnel_number' => $number,
            'last_name' => 'Test', 'first_name' => 'Employee', 'hire_date' => '2026-10-01', 'employment_status' => 'active']);
    }

    private function file(WorkforceEmployee $employee, string $suffix, int $userId): File
    {
        return File::create(['organization_id' => $employee->organization_id, 'user_id' => $userId, 'fileable_type' => WorkforceEmployee::class, 'fileable_id' => $employee->id,
            'name' => $suffix.'.pdf', 'original_name' => $suffix.'.pdf', 'path' => 'org-'.$employee->organization_id.'/workforce/hr/'.$employee->id.'/'.$suffix,
            'mime_type' => 'application/pdf', 'size' => 10, 'disk' => 's3', 'type' => 'document', 'category' => 'workforce_hr',
            'additional_info' => ['visibility' => 'private', 'hr_employee_id' => (int) $employee->id, 'sha256' => str_repeat('a', 64)]]);
    }

    private function allowDocuments(): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canCurrent')->andReturnTrue();
        });
    }
}
