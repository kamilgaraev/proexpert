<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Domain\HR\Services;

use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\HrDocument;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
use App\Models\File;
use App\Models\User;
use App\Services\Storage\FileService;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class HrDocumentService
{
    public function __construct(private readonly HrAccess $access, private readonly FileService $storage, private readonly HrDocumentRetentionPolicy $retention) {}

    public function list(int $organizationId, int $employeeId, ?User $actor): array
    {
        $this->access->assert($organizationId, $actor, 'workforce.hr.documents.read');
        $this->employee($organizationId, $employeeId);
        return HrDocument::query()->where('organization_id', $organizationId)->where('employee_id', $employeeId)->latest()->limit(200)->get()->all();
    }

    public function upload(int $organizationId, int $employeeId, UploadedFile $upload, ?User $actor): File
    {
        $this->access->assert($organizationId, $actor, 'workforce.hr.documents.manage');
        $this->employee($organizationId, $employeeId);
        Validator::make(['file' => $upload], ['file' => ['required', 'file', 'mimes:pdf,png,jpg,jpeg,docx', 'max:12288']])->validate();
        $stream = fopen($upload->getRealPath(), 'rb');
        if ($stream === false) {
            throw new DomainException(trans_message('workforce.errors.hr_file_invalid'));
        }
        try {
            return $this->store($organizationId, $employeeId, $stream, $upload->getMimeType() ?? 'application/octet-stream',
                hash_file('sha256', $upload->getRealPath()), basename($upload->getClientOriginalName()), $actor);
        } finally {
            fclose($stream);
        }
    }

    /** Printable artifact is a draft for manual paper signing. It is never a legal signature. */
    public function printable(int $organizationId, int $employeeId, array $payload, ?User $actor): File
    {
        $this->access->assert($organizationId, $actor, 'workforce.hr.documents.manage');
        $employee = $this->employee($organizationId, $employeeId);
        $data = Validator::make($payload, ['title' => ['required', 'string', 'max:255'], 'body' => ['required', 'string', 'max:40000']])->validate();
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<!doctype html><html lang="ru"><meta charset="utf-8"><title>'.$escape($data['title']).'</title>'
            .'<style>body{font:14px serif;max-width:180mm;margin:20mm auto}pre{white-space:pre-wrap;font:inherit}@media print{body{margin:0}}</style>'
            .'<h1>'.$escape($data['title']).'</h1><p>'.$escape($employee->full_name).' / '.$escape($employee->personnel_number).'</p>'
            .'<pre>'.$escape($data['body']).'</pre><p>'.trans_message('workforce.hr.paper_signature_line').'</p></html>';
        return $this->store($organizationId, $employeeId, $html, 'text/html; charset=utf-8', hash('sha256', $html), 'hr-paper-draft.html', $actor);
    }

    private function store(int $organizationId, int $employeeId, mixed $contents, string $mime, string $sha256, string $name, User $actor): File
    {
        $path = 'org-'.$organizationId.'/workforce/hr/'.$employeeId.'/'.Str::uuid();
        $stored = $this->storage->putPrivate($path, $contents, $mime, $sha256);
        // S3 write precedes the reference: a failed insert cannot publish a nonexistent artifact.
        return File::create(['organization_id' => $organizationId, 'user_id' => $actor->id, 'fileable_type' => WorkforceEmployee::class,
            'fileable_id' => $employeeId, 'name' => $name, 'original_name' => $name, 'path' => $path, 'mime_type' => $mime,
            'size' => $stored->sizeBytes, 'disk' => 's3', 'type' => 'document', 'category' => 'workforce_hr',
            'additional_info' => ['visibility' => 'private', 'sha256' => $sha256, 'hr_employee_id' => $employeeId]]);
    }

    public function register(int $organizationId, int $employeeId, array $payload, ?User $actor): HrDocument
    {
        $this->access->assert($organizationId, $actor, 'workforce.hr.documents.manage');
        $this->employee($organizationId, $employeeId);
        $data = Validator::make($payload, ['document_type' => ['required', 'string', 'max:60'], 'title' => ['required', 'string', 'max:255'],
            'file_id' => ['required', 'integer'], 'retention_class' => ['required', 'string', 'max:80'], 'retain_until' => ['nullable', 'date_format:Y-m-d'],
            'document_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'case_closed_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:document_date'],
            'closure_basis_reference' => ['nullable', 'required_with:case_closed_on', 'string', 'max:255'],
            'supersedes_id' => ['nullable', 'integer']])->validate();
        $data['retention_policy_metadata'] = $this->retention->classify($data, $data['case_closed_on'] ?? null);
        $this->employeeFile($organizationId, $employeeId, (int) $data['file_id']);
        return DB::transaction(function () use ($organizationId, $employeeId, $data, $actor): HrDocument {
            $previous = ! empty($data['supersedes_id']) ? HrDocument::query()->where('organization_id', $organizationId)
                ->where('employee_id', $employeeId)->lockForUpdate()->findOrFail($data['supersedes_id']) : null;
            if ($previous && ($previous->document_type !== $data['document_type'] || HrDocument::query()->where('supersedes_id', $previous->id)->exists())) {
                throw new DomainException(trans_message('workforce.errors.workflow_transition_forbidden'));
            }
            return HrDocument::create($data + ['organization_id' => $organizationId, 'employee_id' => $employeeId,
                'revision' => $previous ? $previous->revision + 1 : 1, 'recorded_by' => $actor->id, 'evidence_kind' => 'unsigned']);
        });
    }

    public function paperEvidence(int $organizationId, int $employeeId, int $documentId, array $payload, ?User $actor): HrDocument
    {
        $this->access->assert($organizationId, $actor, 'workforce.hr.documents.manage');
        $data = Validator::make($payload, ['evidence_file_id' => ['required', 'integer'], 'expected_revision' => ['required', 'integer', 'min:1'],
            'signed_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'paper_original_location' => ['required', 'string', 'max:500']])->validate();
        $fileId = (int) $data['evidence_file_id'];
        $file = $this->employeeFile($organizationId, $employeeId, $fileId);
        if (! in_array($file->mime_type, ['application/pdf', 'image/png', 'image/jpeg'], true)) {
            throw new DomainException(trans_message('workforce.errors.hr_file_invalid'));
        }
        return DB::transaction(function () use ($organizationId, $employeeId, $documentId, $fileId, $actor, $data): HrDocument {
            $document = $this->document($organizationId, $employeeId, $documentId, true);
            if ((int) $document->revision !== (int) $data['expected_revision'] || $data['signed_on'] < $document->document_date->toDateString()
                || (int) $document->file_id === $fileId || $document->evidence_kind !== 'unsigned' || $document->acknowledged_at !== null || HrDocument::query()->where('supersedes_id', $documentId)->exists()) {
                throw new DomainException(trans_message('workforce.errors.workflow_transition_forbidden'));
            }
            $document->update(['evidence_kind' => 'paper_signed', 'evidence_file_id' => $fileId, 'acknowledged_at' => now(), 'acknowledged_by' => $actor->id,
                'paper_evidence_metadata' => ['signed_on' => $data['signed_on'], 'paper_original_location' => $data['paper_original_location'],
                    'matching_revision' => (int) $document->revision, 'recorded_by' => (int) $actor->id, 'recorded_at' => now()->toIso8601String(),
                    'declaration' => 'operator_registered_paper_evidence']]);
            return $document->refresh();
        });
    }

    public function acknowledge(int $organizationId, int $employeeId, int $documentId, ?User $actor): HrDocument
    {
        $this->access->assert($organizationId, $actor, 'workforce.hr.documents.manage');
        return DB::transaction(function () use ($organizationId, $employeeId, $documentId, $actor): HrDocument {
            $document = $this->document($organizationId, $employeeId, $documentId, true);
            if ($document->acknowledged_at !== null || HrDocument::query()->where('supersedes_id', $documentId)->exists()) {
                throw new DomainException(trans_message('workforce.errors.workflow_transition_forbidden'));
            }
            $document->update(['evidence_kind' => 'manual_acknowledgement', 'acknowledged_at' => now(), 'acknowledged_by' => $actor->id]);
            return $document->refresh();
        });
    }

    public function download(int $organizationId, int $employeeId, int $documentId, bool $evidence, ?User $actor): array
    {
        $this->access->assert($organizationId, $actor, 'workforce.hr.documents.read');
        $document = $this->document($organizationId, $employeeId, $documentId);
        $fileId = $evidence ? $document->evidence_file_id : $document->file_id;
        if ($fileId === null) {
            throw new DomainException(trans_message('workforce.errors.hr_file_invalid'));
        }
        $file = $this->employeeFile($organizationId, $employeeId, (int) $fileId);
        return ['url' => $this->storage->temporaryDownloadUrl($file->path, 120), 'expires_in' => 120];
    }

    public function file(int $organizationId, int $fileId): File
    {
        $file = File::query()->where('organization_id', $organizationId)->findOrFail($fileId);
        if ($file->disk !== 's3' || ! str_starts_with($file->path, 'org-'.$organizationId.'/workforce/hr/')
            || ($file->additional_info['visibility'] ?? null) !== 'private') {
            throw new DomainException(trans_message('workforce.errors.hr_file_invalid'));
        }
        return $file;
    }

    private function employeeFile(int $organizationId, int $employeeId, int $fileId): File
    {
        $file = $this->file($organizationId, $fileId);
        if ((int) ($file->additional_info['hr_employee_id'] ?? 0) !== $employeeId || $file->fileable_type !== WorkforceEmployee::class || (int) $file->fileable_id !== $employeeId) {
            throw new DomainException(trans_message('workforce.errors.hr_file_invalid'));
        }
        return $file;
    }

    private function document(int $organizationId, int $employeeId, int $documentId, bool $lock = false): HrDocument
    {
        $this->employee($organizationId, $employeeId);
        return HrDocument::query()->where('organization_id', $organizationId)->where('employee_id', $employeeId)
            ->when($lock, fn ($query) => $query->lockForUpdate())->findOrFail($documentId);
    }

    private function employee(int $organizationId, int $employeeId): WorkforceEmployee
    {
        return WorkforceEmployee::query()->where('organization_id', $organizationId)->findOrFail($employeeId);
    }
}
