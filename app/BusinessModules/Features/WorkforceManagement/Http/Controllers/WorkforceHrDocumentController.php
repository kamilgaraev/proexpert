<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Http\Controllers;

use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Services\HrDocumentService;
use App\BusinessModules\Features\WorkforceManagement\Http\Requests\HR\HrDocumentRequest;
use App\BusinessModules\Features\WorkforceManagement\Http\Requests\HR\HrDocumentUploadRequest;
use App\BusinessModules\Features\WorkforceManagement\Http\Requests\HR\HrPrintableDocumentRequest;
use App\BusinessModules\Features\WorkforceManagement\Http\Requests\HR\HrPaperEvidenceRequest;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WorkforceHrDocumentController extends Controller
{
    public function __construct(private readonly HrDocumentService $documents) {}

    public function index(Request $request, int $employeeId): JsonResponse
    {
        return $this->response(fn () => $this->documents->list($this->organization($request), $employeeId, $request->user()));
    }

    public function upload(HrDocumentUploadRequest $request, int $employeeId): JsonResponse
    {
        return $this->response(fn () => $this->fileResult($this->documents->upload($this->organization($request), $employeeId, $request->file('file'), $request->user())), 201);
    }

    public function printable(HrPrintableDocumentRequest $request, int $employeeId): JsonResponse
    {
        return $this->response(fn () => $this->fileResult($this->documents->printable($this->organization($request), $employeeId, $request->validated(), $request->user())), 201);
    }

    public function store(HrDocumentRequest $request, int $employeeId): JsonResponse
    {
        return $this->response(fn () => $this->documents->register($this->organization($request), $employeeId, $request->validated(), $request->user()), 201);
    }

    public function paperEvidence(HrPaperEvidenceRequest $request, int $employeeId, int $documentId): JsonResponse
    {
        return $this->response(fn () => $this->documents->paperEvidence($this->organization($request), $employeeId, $documentId, $request->validated(), $request->user()));
    }

    public function acknowledge(Request $request, int $employeeId, int $documentId): JsonResponse
    {
        return $this->response(fn () => $this->documents->acknowledge($this->organization($request), $employeeId, $documentId, $request->user()));
    }

    public function download(Request $request, int $employeeId, int $documentId): JsonResponse
    {
        return $this->response(fn () => $this->documents->download($this->organization($request), $employeeId, $documentId, $request->boolean('evidence'), $request->user()));
    }

    private function fileResult(\App\Models\File $file): array
    {
        return ['file_id' => $file->id, 'name' => $file->name, 'mime_type' => $file->mime_type, 'size' => $file->size, 'sha256' => $file->additional_info['sha256']];
    }

    private function organization(Request $request): int
    {
        return (int) $request->attributes->get('current_organization_id');
    }

    private function response(callable $operation, int $status = 200): JsonResponse
    {
        try {
            return AdminResponse::success($operation(), null, $status);
        } catch (\DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        }
    }
}
