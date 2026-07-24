<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Http\Controllers;

use App\BusinessModules\Core\Mdm\Http\Requests\ImportMdmFileRequest;
use App\BusinessModules\Core\Mdm\Http\Requests\ImportMdmRowsRequest;
use App\BusinessModules\Core\Mdm\Services\MdmFileImportParser;
use App\BusinessModules\Core\Mdm\Services\MdmImportService;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;

class MdmImportsController extends MdmBaseController
{
    public function __construct(
        private readonly MdmImportService $importService,
        private readonly MdmFileImportParser $fileImportParser
    ) {}

    public function importPreview(ImportMdmRowsRequest $request): JsonResponse
    {
        return $this->handle($request, 'MDM import preview failed', 'mdm.errors.import_failed', function () use ($request): JsonResponse {
            $validated = $request->validated();
            $batch = $this->importService->preview($request->organizationId(), $validated['entity_type'], $validated['rows'], $request->user()?->id, $validated['source'] ?? 'manual');

            return AdminResponse::success($batch, trans_message('mdm.messages.import_preview_ready'));
        });
    }

    public function importApply(ImportMdmRowsRequest $request): JsonResponse
    {
        return $this->handle($request, 'MDM import apply failed', 'mdm.errors.import_failed', function () use ($request): JsonResponse {
            $validated = $request->validated();
            $batch = $this->importService->apply($request->organizationId(), $validated['entity_type'], $validated['rows'], $request->user()?->id, $validated['source'] ?? 'manual');

            return AdminResponse::success($batch, trans_message('mdm.messages.import_applied'));
        });
    }

    public function fileImportPreview(ImportMdmFileRequest $request): JsonResponse
    {
        return $this->handle($request, 'MDM file import preview failed', 'mdm.errors.import_failed', function () use ($request): JsonResponse {
            $validated = $request->validated();
            $rows = $this->fileImportParser->parse($validated['file']->getRealPath(), $validated['mapping'] ?? []);
            $batch = $this->importService->preview($request->organizationId(), $validated['entity_type'], $rows, $request->user()?->id, 'file');

            return AdminResponse::success($batch, trans_message('mdm.messages.import_preview_ready'));
        });
    }

    public function fileImportApply(ImportMdmFileRequest $request): JsonResponse
    {
        return $this->handle($request, 'MDM file import apply failed', 'mdm.errors.import_failed', function () use ($request): JsonResponse {
            $validated = $request->validated();
            $rows = $this->fileImportParser->parse($validated['file']->getRealPath(), $validated['mapping'] ?? []);
            $batch = $this->importService->apply($request->organizationId(), $validated['entity_type'], $rows, $request->user()?->id, 'file');

            return AdminResponse::success($batch, trans_message('mdm.messages.import_applied'));
        });
    }
}
