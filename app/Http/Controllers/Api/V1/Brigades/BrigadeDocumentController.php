<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Brigades;

use App\BusinessModules\Contractors\Brigades\Domain\Services\BrigadeWorkflowService;
use App\BusinessModules\Contractors\Brigades\Support\BrigadeStatuses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Brigades\StoreBrigadeDocumentRequest;
use App\Http\Resources\Brigades\BrigadeDocumentResource;
use App\Http\Responses\AdminResponse;
use App\Services\Storage\FileService;
use Illuminate\Http\JsonResponse;

class BrigadeDocumentController extends Controller
{
    public function __construct(
        private readonly BrigadeWorkflowService $workflowService,
        private readonly FileService $fileService
    ) {
    }

    public function index(): JsonResponse
    {
        $brigade = $this->workflowService->getOwnedBrigade(auth()->user());

        return AdminResponse::success(BrigadeDocumentResource::collection($brigade->documents()->latest()->get()));
    }

    public function store(StoreBrigadeDocumentRequest $request): JsonResponse
    {
        $brigade = $this->workflowService->getOwnedBrigade($request->user());
        $file = $request->file('document');
        $path = $this->fileService->upload($file, 'brigades/documents');

        if ($path === false) {
            return AdminResponse::error(trans_message('brigades.document_upload_failed'), 500);
        }

        $document = $brigade->documents()->create([
            'title' => $request->string('title')->toString(),
            'document_type' => $request->string('document_type')->toString(),
            'file_path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'verification_status' => BrigadeStatuses::DOCUMENT_PENDING,
        ]);

        return AdminResponse::success(new BrigadeDocumentResource($document), trans_message('brigades.document_uploaded'), 201);
    }

    public function destroy(int $documentId): JsonResponse
    {
        $brigade = $this->workflowService->getOwnedBrigade(auth()->user());
        $document = $brigade->documents()->whereKey($documentId)->firstOrFail();
        $this->fileService->delete($document->file_path);
        $document->delete();

        return AdminResponse::success(null, trans_message('brigades.document_deleted'));
    }
}
