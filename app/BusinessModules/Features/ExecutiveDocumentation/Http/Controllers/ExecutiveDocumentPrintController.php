<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Http\Controllers;

use App\BusinessModules\Features\ExecutiveDocumentation\Http\Requests\RenderExecutiveDocumentRequest;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentRenderService;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentPreparationService;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentPrintPackageService;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentLegalArchiveService;
use App\BusinessModules\Features\ExecutiveDocumentation\Http\Requests\PrepareExecutiveDocumentRequest;
use App\BusinessModules\Features\ExecutiveDocumentation\Http\Resources\ExecutiveDocumentVersionResource;
use App\Http\Responses\AdminResponse;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Exceptions\BusinessLogicException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use DomainException;

final class ExecutiveDocumentPrintController extends Controller
{
    public function __construct(private readonly ExecutiveDocumentRenderService $service) {}

    public function render(RenderExecutiveDocumentRequest $request, int $versionId): Response|JsonResponse
    {
        return $this->respond(function () use ($request, $versionId): Response {
        $pdf = $this->service->render($versionId, (int) $request->user()->id, $request->validated('template_version'));
        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="executive-document-'.$versionId.'.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        });
    }

    public function prepare(PrepareExecutiveDocumentRequest $request, int $documentId, ExecutiveDocumentPreparationService $preparation): JsonResponse
    {
        return $this->respond(fn (): JsonResponse => AdminResponse::success(new ExecutiveDocumentVersionResource(
            $preparation->prepare($documentId, (int) $request->user()->id, $request->validated()),
        )));
    }

    public function package(Request $request, int $transmittalId, ExecutiveDocumentPrintPackageService $packages): Response|JsonResponse
    {
        return $this->respond(fn (): Response => response($packages->build($transmittalId, (int) $request->user()->id), 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="executive-package-'.$transmittalId.'.zip"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]));
    }

    public function legalArchive(Request $request, int $versionId, ExecutiveDocumentLegalArchiveService $archive): JsonResponse
    {
        return $this->respond(function () use ($request, $versionId, $archive): JsonResponse {
        $version = $archive->link($versionId, (int) $request->user()->id);
        return AdminResponse::success([
            'executive_document_version_id' => $versionId,
            'legal_archive_document_id' => (int) $version->document_id,
            'legal_archive_version_id' => (int) $version->id,
            'status' => $version->status,
            'processing_status' => $version->processing_status,
        ]);
        });
    }

    private function respond(callable $operation): Response|JsonResponse
    {
        try {
            return $operation();
        } catch (BusinessLogicException $exception) {
            $status = in_array($exception->getCode(), [400, 403, 404, 409, 422], true) ? $exception->getCode() : 409;
            return AdminResponse::error($exception->getMessage(), $status);
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('executive_documentation.errors.document_not_found'), 404);
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        }
    }
}
