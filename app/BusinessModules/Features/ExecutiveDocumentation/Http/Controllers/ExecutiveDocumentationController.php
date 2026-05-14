<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Http\Controllers;

use App\BusinessModules\Features\ExecutiveDocumentation\Http\Resources\ExecutiveDocumentRemarkResource;
use App\BusinessModules\Features\ExecutiveDocumentation\Http\Resources\ExecutiveDocumentResource;
use App\BusinessModules\Features\ExecutiveDocumentation\Http\Resources\ExecutiveDocumentSetResource;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentVersion;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentationService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ExecutiveDocumentationController extends Controller
{
    public function __construct(
        private readonly ExecutiveDocumentationService $service,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');

            return AdminResponse::success(
                ExecutiveDocumentSetResource::collection($this->service->listSets($organizationId, $request->only(['project_id'])))
            );
        } catch (\Throwable $e) {
            return $this->failed('index', null, $e);
        }
    }

    public function storeSet(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'project_id' => ['required', 'integer'],
                'title' => ['required', 'string', 'max:255'],
                'stage_name' => ['nullable', 'string', 'max:255'],
                'zone_name' => ['nullable', 'string', 'max:255'],
                'planned_transmittal_date' => ['nullable', 'date'],
                'metadata' => ['nullable', 'array'],
            ]);
            $organizationId = (int) $request->attributes->get('current_organization_id');

            return AdminResponse::success(
                new ExecutiveDocumentSetResource($this->service->createSet($organizationId, (int) auth()->id(), $validated)),
                trans_message('executive_documentation.messages.set_created'),
                201
            );
        } catch (ValidationException $e) {
            return AdminResponse::error($e->getMessage(), 422, $e->errors());
        } catch (DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->failed('store_set', null, $e);
        }
    }

    public function showSet(Request $request, int $id): JsonResponse
    {
        try {
            $set = $this->service->findSet($id, (int) $request->attributes->get('current_organization_id'));

            if ($set === null) {
                return AdminResponse::error(trans_message('executive_documentation.errors.not_found'), 404);
            }

            return AdminResponse::success(new ExecutiveDocumentSetResource($set));
        } catch (\Throwable $e) {
            return $this->failed('show_set', $id, $e);
        }
    }

    public function storeDocument(Request $request, int $setId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'document_type' => ['required', 'string', Rule::in([
                    'hidden_work_act',
                    'executive_scheme',
                    'material_certificate',
                    'test_protocol',
                    'work_log_extract',
                    'photo_report',
                    'handover_package',
                    'other',
                ])],
                'title' => ['required', 'string', 'max:255'],
                'work_type_name' => ['nullable', 'string', 'max:255'],
                'section_name' => ['nullable', 'string', 'max:255'],
                'completed_work_id' => ['nullable', 'integer'],
                'inspection_date' => ['nullable', 'date'],
                'participants' => ['nullable', 'array'],
                'initial_version' => ['nullable', 'array'],
                'initial_version.file_url' => ['required_with:initial_version', 'string', 'max:2000'],
                'initial_version.version_number' => ['required_with:initial_version', 'string', 'max:40'],
                'initial_version.uploaded_at' => ['nullable', 'date'],
                'metadata' => ['nullable', 'array'],
            ]);
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $set = $this->service->findSet($setId, $organizationId);

            if ($set === null) {
                return AdminResponse::error(trans_message('executive_documentation.errors.not_found'), 404);
            }

            return AdminResponse::success(
                new ExecutiveDocumentResource($this->service->addDocument($set, (int) auth()->id(), $validated)),
                trans_message('executive_documentation.messages.document_created'),
                201
            );
        } catch (ValidationException $e) {
            return AdminResponse::error($e->getMessage(), 422, $e->errors());
        } catch (DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->failed('store_document', $setId, $e);
        }
    }

    public function submit(Request $request, int $id): JsonResponse
    {
        return $this->documentAction($request, $id, 'submit');
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        return $this->documentAction($request, $id, 'approve');
    }

    public function storeRemark(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'body' => ['required', 'string', 'max:5000'],
                'severity' => ['nullable', 'string', Rule::in(['minor', 'major', 'critical'])],
            ]);
            $document = $this->findDocument($request, $id);

            return AdminResponse::success(
                new ExecutiveDocumentRemarkResource($this->service->addRemark($document, (int) auth()->id(), $validated)),
                trans_message('executive_documentation.messages.remark_created'),
                201
            );
        } catch (ValidationException $e) {
            return AdminResponse::error($e->getMessage(), 422, $e->errors());
        } catch (DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->failed('store_remark', $id, $e);
        }
    }

    public function resolveRemark(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate(['resolution_comment' => ['required', 'string', 'max:1000']]);
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $remark = $this->service->findRemark($id, $organizationId);

            if ($remark === null) {
                return AdminResponse::error(trans_message('executive_documentation.errors.remark_not_found'), 404);
            }

            return AdminResponse::success(
                new ExecutiveDocumentRemarkResource($this->service->resolveRemark($remark, (int) auth()->id(), $validated['resolution_comment']))
            );
        } catch (ValidationException $e) {
            return AdminResponse::error($e->getMessage(), 422, $e->errors());
        } catch (\Throwable $e) {
            return $this->failed('resolve_remark', $id, $e);
        }
    }

    public function transmit(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'transmittal_number' => ['required', 'string', 'max:80'],
                'comment' => ['nullable', 'string', 'max:1000'],
                'metadata' => ['nullable', 'array'],
            ]);
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $set = $this->service->findSet($id, $organizationId);

            if ($set === null) {
                return AdminResponse::error(trans_message('executive_documentation.errors.not_found'), 404);
            }

            return AdminResponse::success(new ExecutiveDocumentSetResource($this->service->transmit($set, (int) auth()->id(), $validated)));
        } catch (ValidationException $e) {
            return AdminResponse::error($e->getMessage(), 422, $e->errors());
        } catch (DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->failed('transmit', $id, $e);
        }
    }

    public function deleteVersion(Request $request, int $documentId, int $versionId): JsonResponse
    {
        try {
            $document = $this->findDocument($request, $documentId);
            $version = ExecutiveDocumentVersion::query()
                ->where('organization_id', $document->organization_id)
                ->where('document_id', $document->id)
                ->find($versionId);

            if ($version === null) {
                return AdminResponse::error(trans_message('executive_documentation.errors.version_not_found'), 404);
            }

            $this->service->deleteVersion($document, $version);

            return AdminResponse::success(null, trans_message('executive_documentation.messages.version_deleted'));
        } catch (DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->failed('delete_version', $versionId, $e);
        }
    }

    private function documentAction(Request $request, int $id, string $action): JsonResponse
    {
        try {
            $validated = $request->validate(['comment' => ['nullable', 'string', 'max:1000']]);
            $document = $this->findDocument($request, $id);
            $updated = $action === 'submit'
                ? $this->service->submit($document, (int) auth()->id(), $validated['comment'] ?? null)
                : $this->service->approve($document, (int) auth()->id(), $validated['comment'] ?? null);

            return AdminResponse::success(new ExecutiveDocumentResource($updated));
        } catch (ValidationException $e) {
            return AdminResponse::error($e->getMessage(), 422, $e->errors());
        } catch (DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->failed($action, $id, $e);
        }
    }

    private function findDocument(Request $request, int $id)
    {
        $document = $this->service->findDocument($id, (int) $request->attributes->get('current_organization_id'));

        if ($document === null) {
            throw new DomainException(trans_message('executive_documentation.errors.document_not_found'));
        }

        return $document;
    }

    private function failed(string $action, ?int $id, \Throwable $e): JsonResponse
    {
        Log::error("executive_documentation.{$action}.error", [
            'id' => $id,
            'user_id' => auth()->id(),
            'error' => $e->getMessage(),
        ]);

        return AdminResponse::error(trans_message("executive_documentation.errors.{$action}_failed"), 500);
    }
}
