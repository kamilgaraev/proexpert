<?php

declare(strict_types=1);

namespace Tests\Feature\WorkforceManagement\Timekeeping;

use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\HrDocument;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
use App\BusinessModules\Features\WorkforceManagement\Domain\Timekeeping\Models\TimekeepingPeriod;
use App\BusinessModules\Features\WorkforceManagement\Domain\Timekeeping\Services\TimekeepingService;
use App\Models\File;
use App\Services\Storage\FileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class TimekeepingHrGroundsTest extends TestCase
{
    use RefreshDatabase;

    public function test_review_binds_hr_document_id_revision_and_signed_employee_file_bytes(): void
    {
        [$context, $employee, $document] = $this->grounds();
        $this->storage('signed scan');
        $result = $this->review($context, $employee, $document);

        self::assertSame((int) $document->id, $result['overtime_consent_document_id']['id']);
        self::assertSame(1, $result['overtime_consent_document_id']['revision']);
        self::assertSame((int) $document->evidence_file_id, $result['overtime_consent_document_id']['evidence_file_id']);
        self::assertSame(hash('sha256', 'signed scan'), $result['overtime_consent_document_id']['evidence_sha256']);
    }

    public function test_foreign_employee_scan_cannot_be_used_even_inside_same_organization(): void
    {
        [$context, $employee, $document] = $this->grounds();
        $foreignEmployee = $this->employee($context, 'OTHER');
        File::query()->findOrFail($document->evidence_file_id)->update(['fileable_id' => $foreignEmployee->id, 'additional_info' => ['visibility' => 'private', 'hr_employee_id' => $foreignEmployee->id, 'sha256' => hash('sha256', 'signed scan')]]);
        $this->expectException(\DomainException::class);
        $this->review($context, $employee, $document);
    }

    public function test_declared_signature_for_other_revision_cannot_be_used(): void
    {
        [$context, $employee, $document] = $this->grounds();
        $document->update(['paper_evidence_metadata' => array_replace($document->paper_evidence_metadata, ['matching_revision' => 2])]);
        $this->expectException(\DomainException::class);
        $this->review($context, $employee, $document);
    }

    public function test_altered_signed_scan_is_rejected_against_registered_digest(): void
    {
        [$context, $employee, $document] = $this->grounds();
        $this->storage('altered bytes');
        $this->expectException(\DomainException::class);
        $this->review($context, $employee, $document);
    }

    public function test_unsigned_document_is_not_legal_review_evidence(): void
    {
        [$context, $employee, $document] = $this->grounds();
        $document->update(['evidence_kind' => 'unsigned']);
        $this->expectException(\DomainException::class);
        $this->review($context, $employee, $document);
    }

    public function test_other_employee_hr_document_is_rejected(): void
    {
        [$context, $employee, $document] = $this->grounds();
        $document->update(['employee_id' => $this->employee($context, 'OTHER')->id]);
        $this->expectException(\DomainException::class);
        $this->review($context, $employee, $document);
    }

    private function grounds(): array
    {
        $context = AdminApiTestContext::create();
        $employee = $this->employee($context, 'EVIDENCE');
        $original = $this->file($context, $employee, 'draft', 'text/html');
        $scan = $this->file($context, $employee, 'scan', 'application/pdf');
        $document = HrDocument::query()->create(['organization_id' => $context->organization->id, 'employee_id' => $employee->id, 'document_type' => 'overtime_consent', 'title' => 'Согласие на сверхурочную работу', 'file_id' => $original->id, 'document_date' => '2026-09-01', 'revision' => 1, 'retention_class' => 'unclassified_hold', 'retention_policy_metadata' => [], 'evidence_kind' => 'paper_signed', 'evidence_file_id' => $scan->id, 'paper_evidence_metadata' => ['matching_revision' => 1, 'signed_on' => '2026-09-01', 'paper_original_location' => 'Архив, дело 1'], 'recorded_by' => $context->user->id]);

        return [$context, $employee, $document];
    }

    private function employee(AdminApiTestContext $context, string $number): WorkforceEmployee
    {
        return WorkforceEmployee::query()->create(['organization_id' => $context->organization->id, 'personnel_number' => $number, 'first_name' => 'Иван', 'last_name' => 'Петров', 'hire_date' => '2026-01-01', 'employment_status' => 'active']);
    }

    private function file(AdminApiTestContext $context, WorkforceEmployee $employee, string $suffix, string $mime): File
    {
        return File::query()->create(['organization_id' => $context->organization->id, 'user_id' => $context->user->id, 'fileable_type' => WorkforceEmployee::class, 'fileable_id' => $employee->id, 'name' => $suffix, 'original_name' => $suffix, 'path' => 'org-'.$context->organization->id.'/workforce/hr/'.$employee->id.'/'.$suffix, 'mime_type' => $mime, 'size' => 11, 'disk' => 's3', 'type' => 'document', 'category' => 'workforce_hr', 'additional_info' => ['visibility' => 'private', 'hr_employee_id' => $employee->id, 'sha256' => hash('sha256', 'signed scan')]]);
    }

    private function storage(string $bytes): void
    {
        $this->mock(FileService::class, function (MockInterface $mock) use ($bytes): void {
            $mock->shouldReceive('readCurrent')->once()->andReturnUsing(static function () use ($bytes) {
                $stream = fopen('php://temp', 'w+');
                fwrite($stream, $bytes);
                rewind($stream);

                return $stream;
            });
        });
    }

    private function review(AdminApiTestContext $context, WorkforceEmployee $employee, HrDocument $document): array
    {
        $period = (new TimekeepingPeriod)->forceFill(['id' => 1, 'month' => '2026-09-01']);

        return (new \ReflectionMethod(TimekeepingService::class, 'reviewEvidence'))->invoke(app(TimekeepingService::class), (int) $context->organization->id, (int) $employee->id, '2026-09-02', ['overtime_consent_document_id' => (int) $document->id], $period, 1, $context->user);
    }
}
