<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Http\Controllers;

use App\BusinessModules\Features\ExecutiveDocumentation\Http\Resources\ExecutiveDocumentRemarkResource;
use App\BusinessModules\Features\ExecutiveDocumentation\Http\Resources\ExecutiveDocumentResource;
use App\BusinessModules\Features\ExecutiveDocumentation\Http\Resources\ExecutiveDocumentSetResource;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentVersion;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentationService;
use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceScope;
use App\BusinessModules\Features\HandoverAcceptance\Models\ProjectLocation;
use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\CompletedWork;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\Material;
use App\Models\Supplier;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\File;
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

    public function references(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'project_id' => ['required', 'integer'],
            ]);
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $projectId = (int) $validated['project_id'];

            return AdminResponse::success([
                'completed_works' => CompletedWork::query()
                    ->where('organization_id', $organizationId)
                    ->where('project_id', $projectId)
                    ->with(['workType:id,name', 'journalEntry:id,entry_number,entry_date'])
                    ->latest('completion_date')
                    ->limit(100)
                    ->get()
                    ->map(static fn (CompletedWork $work): array => [
                        'id' => $work->id,
                        'name' => $work->workType?->name ?? $work->notes ?? ('Работа #' . $work->id),
                        'work_type_name' => $work->workType?->name,
                        'completion_date' => $work->completion_date?->format('Y-m-d'),
                        'quantity' => $work->quantity,
                        'journal_entry_id' => $work->journal_entry_id,
                        'journal_entry_number' => $work->journalEntry?->entry_number,
                    ])
                    ->values(),
                'project_locations' => ProjectLocation::query()
                    ->where('organization_id', $organizationId)
                    ->where('project_id', $projectId)
                    ->orderBy('path')
                    ->orderBy('name')
                    ->limit(200)
                    ->get(['id', 'name', 'code', 'location_type', 'path'])
                    ->values(),
                'acceptance_scopes' => AcceptanceScope::query()
                    ->where('organization_id', $organizationId)
                    ->where('project_id', $projectId)
                    ->with('location:id,name,code,path')
                    ->latest('id')
                    ->limit(100)
                    ->get()
                    ->map(static fn (AcceptanceScope $scope): array => [
                        'id' => $scope->id,
                        'title' => $scope->title,
                        'status' => $scope->status,
                        'project_location_id' => $scope->project_location_id,
                        'location_name' => $scope->location?->name,
                    ])
                    ->values(),
                'quality_defects' => QualityDefect::query()
                    ->where('organization_id', $organizationId)
                    ->where('project_id', $projectId)
                    ->latest('id')
                    ->limit(100)
                    ->get(['id', 'defect_number', 'title', 'status', 'location_name', 'completed_work_id'])
                    ->values(),
            ]);
        } catch (ValidationException $e) {
            return AdminResponse::error($e->getMessage(), 422, $e->errors());
        } catch (\Throwable $e) {
            return $this->failed('references', null, $e);
        }
    }

    public function storeDocument(Request $request, int $setId): JsonResponse
    {
        try {
            $documentType = (string) $request->input('document_type');
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
                'initial_version' => ['required', 'array'],
                'initial_version.file' => ['required', File::types(['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png'])->max(25 * 1024)],
                'initial_version.version_number' => ['required_with:initial_version', 'string', 'max:40'],
                'initial_version.uploaded_at' => ['nullable', 'date'],
                'metadata' => ['nullable', 'array'],
                ...$this->documentTypeRules($documentType),
            ]);
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $set = $this->service->findSet($setId, $organizationId);

            if ($set === null) {
                return AdminResponse::error(trans_message('executive_documentation.errors.not_found'), 404);
            }

            $validated = $this->normalizeDocumentReferences($validated, $set);

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

    /**
     * @return array<string, array<int, string>>
     */
    private function documentTypeRules(string $documentType): array
    {
        return match ($documentType) {
            'hidden_work_act' => [
                'completed_work_id' => ['required', 'integer'],
                'metadata.project_location_id' => ['required', 'integer'],
                'inspection_date' => ['required', 'date'],
                'metadata.act_number' => ['required', 'string', 'max:120'],
                'metadata.representative' => ['nullable', 'string', 'max:255'],
            ],
            'executive_scheme' => [
                'metadata.project_location_id' => ['required', 'integer'],
                'completed_work_id' => ['nullable', 'integer'],
                'metadata.drawing_number' => ['required', 'string', 'max:120'],
                'metadata.revision' => ['required', 'string', 'max:80'],
                'metadata.scale' => ['nullable', 'string', 'max:80'],
            ],
            'material_certificate' => [
                'metadata.project_location_id' => ['nullable', 'integer'],
                'metadata.material_id' => ['required', 'integer'],
                'metadata.batch_number' => ['required', 'string', 'max:120'],
                'metadata.supplier_id' => ['required', 'integer'],
                'metadata.certificate_number' => ['required', 'string', 'max:120'],
            ],
            'test_protocol' => [
                'metadata.project_location_id' => ['required', 'integer'],
                'completed_work_id' => ['required', 'integer'],
                'metadata.material_id' => ['nullable', 'integer'],
                'metadata.quality_defect_id' => ['nullable', 'integer'],
                'inspection_date' => ['required', 'date'],
                'metadata.protocol_number' => ['required', 'string', 'max:120'],
                'metadata.test_type' => ['required', 'string', 'max:255'],
                'metadata.laboratory_name' => ['required', 'string', 'max:255'],
            ],
            'work_log_extract' => [
                'metadata.project_location_id' => ['required', 'integer'],
                'metadata.journal_id' => ['required', 'integer'],
                'metadata.journal_entry_id' => ['required', 'integer'],
                'metadata.period' => ['required', 'string', 'max:120'],
                'metadata.page_range' => ['nullable', 'string', 'max:120'],
            ],
            'photo_report' => [
                'metadata.project_location_id' => ['required', 'integer'],
                'completed_work_id' => ['nullable', 'integer'],
                'metadata.quality_defect_id' => ['nullable', 'integer'],
                'inspection_date' => ['required', 'date'],
                'metadata.photo_count' => ['nullable', 'string', 'max:80'],
            ],
            'handover_package' => [
                'metadata.acceptance_scope_id' => ['required', 'integer'],
                'metadata.package_number' => ['required', 'string', 'max:120'],
                'metadata.responsible_person' => ['required', 'string', 'max:255'],
            ],
            'other' => [
                'metadata.document_purpose' => ['required', 'string', 'max:500'],
            ],
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    private function normalizeDocumentReferences(array $validated, ExecutiveDocumentSet $set): array
    {
        $validated = $this->normalizeProjectLocationReference($validated, $set);
        $validated = $this->normalizeCompletedWorkReference($validated, $set);
        $validated = $this->normalizeQualityDefectReference($validated, $set);
        $validated = $this->normalizeAcceptanceScopeReference($validated, $set);

        return match ($validated['document_type'] ?? null) {
            'material_certificate' => $this->normalizeMaterialCertificateReferences($validated, $set),
            'test_protocol' => $this->normalizeTestProtocolReferences($validated, $set),
            'work_log_extract' => $this->normalizeWorkLogExtractReferences($validated, $set),
            default => $validated,
        };
    }

    /**
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    private function normalizeWorkLogExtractReferences(array $validated, ExecutiveDocumentSet $set): array
    {
        $journalId = (int) data_get($validated, 'metadata.journal_id');
        $journal = ConstructionJournal::query()
            ->where('organization_id', $set->organization_id)
            ->where('project_id', $set->project_id)
            ->find($journalId);

        if ($journal === null) {
            throw ValidationException::withMessages([
                'metadata.journal_id' => trans_message('executive_documentation.errors.journal_not_found'),
            ]);
        }

        $entryId = (int) data_get($validated, 'metadata.journal_entry_id');
        $entry = ConstructionJournalEntry::query()
            ->where('journal_id', $journal->id)
            ->find($entryId);

        if ($entry === null) {
            throw ValidationException::withMessages([
                'metadata.journal_entry_id' => trans_message('executive_documentation.errors.journal_entry_not_found'),
            ]);
        }

        $metadata = $validated['metadata'] ?? [];
        $metadata['journal_id'] = $journal->id;
        $metadata['journal_name'] = $journal->name;
        $metadata['journal_number'] = $journal->journal_number;
        $metadata['journal_entry_id'] = $entry->id;
        $metadata['journal_entry_number'] = $entry->entry_number;
        $metadata['journal_entry_date'] = $entry->entry_date?->format('Y-m-d');
        $metadata['work_description'] = $entry->work_description;
        $validated['metadata'] = $metadata;

        return $validated;
    }

    /**
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    private function normalizeProjectLocationReference(array $validated, ExecutiveDocumentSet $set): array
    {
        $locationId = (int) data_get($validated, 'metadata.project_location_id');

        if ($locationId <= 0) {
            return $validated;
        }

        $location = ProjectLocation::query()
            ->where('organization_id', $set->organization_id)
            ->where('project_id', $set->project_id)
            ->find($locationId);

        if ($location === null) {
            throw ValidationException::withMessages([
                'metadata.project_location_id' => trans_message('executive_documentation.errors.project_location_not_found'),
            ]);
        }

        $metadata = is_array($validated['metadata'] ?? null) ? $validated['metadata'] : [];
        $metadata['project_location_id'] = $location->id;
        $metadata['project_location_name'] = $location->name;
        $metadata['project_location_code'] = $location->code;
        $validated['metadata'] = $metadata;
        $validated['section_name'] = $validated['section_name'] ?? $location->name;

        return $validated;
    }

    /**
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    private function normalizeCompletedWorkReference(array $validated, ExecutiveDocumentSet $set): array
    {
        $completedWorkId = (int) ($validated['completed_work_id'] ?? 0);

        if ($completedWorkId <= 0) {
            return $validated;
        }

        $work = CompletedWork::query()
            ->where('organization_id', $set->organization_id)
            ->where('project_id', $set->project_id)
            ->with(['workType:id,name', 'journalEntry:id,entry_number,entry_date'])
            ->find($completedWorkId);

        if ($work === null) {
            throw ValidationException::withMessages([
                'completed_work_id' => trans_message('executive_documentation.errors.completed_work_not_found'),
            ]);
        }

        $metadata = is_array($validated['metadata'] ?? null) ? $validated['metadata'] : [];
        $metadata['completed_work_id'] = $work->id;
        $metadata['completed_work_date'] = $work->completion_date?->format('Y-m-d');
        $metadata['completed_work_quantity'] = (string) $work->quantity;
        $metadata['journal_entry_id'] = $metadata['journal_entry_id'] ?? $work->journal_entry_id;
        $metadata['journal_entry_number'] = $metadata['journal_entry_number'] ?? $work->journalEntry?->entry_number;
        $validated['metadata'] = $metadata;
        $validated['work_type_name'] = $validated['work_type_name'] ?? $work->workType?->name;

        return $validated;
    }

    /**
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    private function normalizeQualityDefectReference(array $validated, ExecutiveDocumentSet $set): array
    {
        $defectId = (int) data_get($validated, 'metadata.quality_defect_id');

        if ($defectId <= 0) {
            return $validated;
        }

        $defect = QualityDefect::query()
            ->where('organization_id', $set->organization_id)
            ->where('project_id', $set->project_id)
            ->find($defectId);

        if ($defect === null) {
            throw ValidationException::withMessages([
                'metadata.quality_defect_id' => trans_message('executive_documentation.errors.quality_defect_not_found'),
            ]);
        }

        $metadata = is_array($validated['metadata'] ?? null) ? $validated['metadata'] : [];
        $metadata['quality_defect_id'] = $defect->id;
        $metadata['quality_defect_number'] = $defect->defect_number;
        $metadata['quality_defect_title'] = $defect->title;
        $validated['metadata'] = $metadata;

        return $validated;
    }

    /**
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    private function normalizeAcceptanceScopeReference(array $validated, ExecutiveDocumentSet $set): array
    {
        $scopeId = (int) data_get($validated, 'metadata.acceptance_scope_id');

        if ($scopeId <= 0) {
            return $validated;
        }

        $scope = AcceptanceScope::query()
            ->where('organization_id', $set->organization_id)
            ->where('project_id', $set->project_id)
            ->with('location:id,name,code')
            ->find($scopeId);

        if ($scope === null) {
            throw ValidationException::withMessages([
                'metadata.acceptance_scope_id' => trans_message('executive_documentation.errors.acceptance_scope_not_found'),
            ]);
        }

        $metadata = is_array($validated['metadata'] ?? null) ? $validated['metadata'] : [];
        $metadata['acceptance_scope_id'] = $scope->id;
        $metadata['acceptance_scope_title'] = $scope->title;
        $metadata['project_location_id'] = $metadata['project_location_id'] ?? $scope->project_location_id;
        $metadata['project_location_name'] = $metadata['project_location_name'] ?? $scope->location?->name;
        $validated['metadata'] = $metadata;
        $validated['section_name'] = $validated['section_name'] ?? $scope->location?->name ?? $scope->title;

        return $validated;
    }

    /**
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    private function normalizeMaterialCertificateReferences(array $validated, ExecutiveDocumentSet $set): array
    {
        $materialId = (int) data_get($validated, 'metadata.material_id');
        $material = Material::query()
            ->where('organization_id', $set->organization_id)
            ->where('is_active', true)
            ->find($materialId);

        if ($material === null) {
            throw ValidationException::withMessages([
                'metadata.material_id' => trans_message('executive_documentation.errors.material_not_found'),
            ]);
        }

        $supplierId = (int) data_get($validated, 'metadata.supplier_id');
        $supplier = Supplier::query()
            ->where('organization_id', $set->organization_id)
            ->where('is_active', true)
            ->find($supplierId);

        if ($supplier === null) {
            throw ValidationException::withMessages([
                'metadata.supplier_id' => trans_message('executive_documentation.errors.supplier_not_found'),
            ]);
        }

        $metadata = $validated['metadata'] ?? [];
        $metadata['material_id'] = $material->id;
        $metadata['material_name'] = $material->name;
        $metadata['material_code'] = $material->code;
        $metadata['supplier_id'] = $supplier->id;
        $metadata['supplier_name'] = $supplier->name;
        $validated['metadata'] = $metadata;

        return $validated;
    }

    /**
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    private function normalizeTestProtocolReferences(array $validated, ExecutiveDocumentSet $set): array
    {
        $materialId = (int) data_get($validated, 'metadata.material_id');

        if ($materialId <= 0) {
            return $validated;
        }

        $material = Material::query()
            ->where('organization_id', $set->organization_id)
            ->where('is_active', true)
            ->find($materialId);

        if ($material === null) {
            throw ValidationException::withMessages([
                'metadata.material_id' => trans_message('executive_documentation.errors.material_not_found'),
            ]);
        }

        $metadata = is_array($validated['metadata'] ?? null) ? $validated['metadata'] : [];
        $metadata['material_id'] = $material->id;
        $metadata['material_name'] = $material->name;
        $metadata['material_code'] = $material->code;
        $validated['metadata'] = $metadata;

        return $validated;
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
