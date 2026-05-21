<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Http\Controllers;

use App\BusinessModules\Features\ExecutiveDocumentation\Http\Resources\ExecutiveDocumentRemarkResource;
use App\BusinessModules\Features\ExecutiveDocumentation\Http\Resources\ExecutiveDocumentResource;
use App\BusinessModules\Features\ExecutiveDocumentation\Http\Resources\ExecutiveDocumentSetResource;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentVersion;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentationService;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\HiddenWorkActAutofillService;
use App\BusinessModules\Features\ExecutiveDocumentation\Support\ExecutiveDocumentProfileRegistry;
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
use App\Models\WorkType;
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
        private readonly ExecutiveDocumentProfileRegistry $profileRegistry,
        private readonly HiddenWorkActAutofillService $hiddenWorkActAutofillService,
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
            $workTypes = $this->service->ensureExecutiveWorkTypes($organizationId);

            return AdminResponse::success([
                'document_profiles' => $this->profileRegistry->all(),
                'work_types' => $workTypes
                    ->map(static fn (WorkType $workType): array => [
                        'id' => $workType->id,
                        'name' => $workType->name,
                        'code' => $workType->code,
                        'category' => $workType->category,
                    ])
                    ->values(),
                'completed_works' => CompletedWork::query()
                    ->where('organization_id', $organizationId)
                    ->where('project_id', $projectId)
                    ->with([
                        'workType:id,name',
                        'journalEntry.journal:id,name,journal_number',
                        'journalEntry.workVolumes.workType:id,name',
                        'journalEntry.workVolumes.estimateItem:id,name,position_number',
                        'journalEntry.workVolumes.measurementUnit:id,name,short_name',
                        'journalEntry.materials:id,journal_entry_id,material_name,quantity,measurement_unit',
                        'journalWorkVolume.workType:id,name',
                        'journalWorkVolume.estimateItem:id,name,position_number',
                        'journalWorkVolume.measurementUnit:id,name,short_name',
                    ])
                    ->latest('completion_date')
                    ->limit(100)
                    ->get()
                    ->map(fn (CompletedWork $work): array => [
                        'id' => $work->id,
                        'name' => $work->workType?->name ?? $work->notes ?? ('Работа #' . $work->id),
                        'work_type_name' => $work->workType?->name,
                        'completion_date' => $work->completion_date?->format('Y-m-d'),
                        'quantity' => $work->quantity,
                        'journal_entry_id' => $work->journal_entry_id,
                        'journal_entry_number' => $work->journalEntry?->entry_number,
                        'hidden_work_act_defaults' => $this->hiddenWorkActAutofillService->forCompletedWorkReference(
                            $work,
                            $organizationId
                        ),
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
                'journals' => ConstructionJournal::query()
                    ->where('organization_id', $organizationId)
                    ->where('project_id', $projectId)
                    ->latest('id')
                    ->limit(100)
                    ->get(['id', 'name', 'journal_number', 'start_date', 'end_date', 'status'])
                    ->map(static fn (ConstructionJournal $journal): array => [
                        'id' => $journal->id,
                        'name' => $journal->name,
                        'journal_number' => $journal->journal_number,
                        'start_date' => $journal->start_date?->format('Y-m-d'),
                        'end_date' => $journal->end_date?->format('Y-m-d'),
                        'status' => $journal->status?->value ?? $journal->status,
                    ])
                    ->values(),
                'journal_entries' => ConstructionJournalEntry::query()
                    ->whereHas('journal', static fn ($query) => $query
                        ->where('organization_id', $organizationId)
                        ->where('project_id', $projectId))
                    ->with([
                        'journal:id,name,journal_number',
                        'workVolumes.workType:id,name',
                        'workVolumes.estimateItem:id,name,position_number',
                        'workVolumes.measurementUnit:id,name,short_name',
                        'materials:id,journal_entry_id,material_name,quantity,measurement_unit',
                        'completedWorks.workType:id,name',
                        'completedWorks.journalWorkVolume.workType:id,name',
                        'completedWorks.journalWorkVolume.estimateItem:id,name,position_number',
                        'completedWorks.journalWorkVolume.measurementUnit:id,name,short_name',
                    ])
                    ->latest('entry_date')
                    ->limit(200)
                    ->get()
                    ->map(fn (ConstructionJournalEntry $entry): array => [
                        'id' => $entry->id,
                        'journal_id' => $entry->journal_id,
                        'journal_name' => $entry->journal?->name,
                        'journal_number' => $entry->journal?->journal_number,
                        'entry_number' => $entry->entry_number,
                        'entry_date' => $entry->entry_date?->format('Y-m-d'),
                        'work_description' => $entry->work_description,
                        'status' => $entry->status?->value ?? $entry->status,
                        'work_volumes' => $entry->workVolumes
                            ->map(static fn ($volume): array => [
                                'id' => $volume->id,
                                'work_name' => $volume->workType?->name ?? $volume->estimateItem?->name,
                                'quantity' => $volume->quantity,
                                'measurement_unit' => $volume->measurementUnit?->short_name ?? $volume->measurementUnit?->name,
                            ])
                            ->values(),
                        'materials' => $entry->materials
                            ->map(static fn ($material): array => [
                                'id' => $material->id,
                                'material_name' => $material->material_name,
                                'quantity' => $material->quantity,
                                'measurement_unit' => $material->measurement_unit,
                            ])
                            ->values(),
                        'hidden_work_act_defaults' => $this->hiddenWorkActAutofillService->forJournalEntryReference(
                            $entry,
                            $organizationId
                        ),
                    ])
                    ->values(),
                'materials' => Material::query()
                    ->where('organization_id', $organizationId)
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->limit(200)
                    ->get(['id', 'name', 'code'])
                    ->values(),
                'suppliers' => Supplier::query()
                    ->where('organization_id', $organizationId)
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->limit(200)
                    ->get(['id', 'name'])
                    ->values(),
                'executive_documents' => ExecutiveDocument::query()
                    ->where('organization_id', $organizationId)
                    ->where('project_id', $projectId)
                    ->latest('id')
                    ->limit(200)
                    ->get(['id', 'document_set_id', 'document_type', 'title', 'status', 'document_date'])
                    ->map(static fn (ExecutiveDocument $document): array => [
                        'id' => $document->id,
                        'document_set_id' => $document->document_set_id,
                        'document_type' => $document->document_type->value,
                        'document_type_label' => $document->document_type->label(),
                        'title' => $document->title,
                        'status' => $document->status->value,
                        'document_date' => $document->document_date?->format('Y-m-d'),
                    ])
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
                'document_type' => ['required', 'string', Rule::in($this->profileRegistry->types())],
                'title' => ['required', 'string', 'max:255'],
                'work_type_id' => ['nullable', 'integer'],
                'work_type_name' => ['nullable', 'string', 'max:255'],
                'section_name' => ['nullable', 'string', 'max:255'],
                'completed_work_id' => ['nullable', 'integer'],
                'document_date' => ['nullable', 'date'],
                'copies_count' => ['nullable', 'integer', 'min:1', 'max:50'],
                'form_variant' => ['nullable', 'string', Rule::in(['order_344', 'sp_48_13330_2019', 'custom'])],
                'journal_entry_id' => ['nullable', 'integer'],
                'inspection_date' => ['nullable', 'date'],
                'participants' => ['nullable', 'array'],
                'profile_data' => ['nullable', 'array'],
                'signatories' => ['nullable', 'array'],
                'relations' => ['nullable', 'array'],
                'relations.*.relation_type' => ['required_with:relations', 'string', 'max:80'],
                'relations.*.target_type' => ['required_with:relations', 'string', 'max:80'],
                'relations.*.target_id' => ['required_with:relations', 'integer'],
                'relations.*.label' => ['nullable', 'string', 'max:255'],
                'relations.*.metadata' => ['nullable', 'array'],
                'initial_version' => ['required', 'array'],
                'initial_version.file' => ['required', File::types(['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png'])->max(25 * 1024)],
                'initial_version.version_number' => ['required_with:initial_version', 'string', 'max:40'],
                'initial_version.uploaded_at' => ['nullable', 'date'],
                'metadata' => ['nullable', 'array'],
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
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    private function normalizeDocumentReferences(array $validated, ExecutiveDocumentSet $set): array
    {
        $validated = $this->normalizeWorkTypeReference($validated, $set);
        $validated = $this->normalizeProjectLocationReference($validated, $set);
        $validated = $this->normalizeCompletedWorkReference($validated, $set);
        $validated = $this->hiddenWorkActAutofillService->applyToDocumentPayload($validated, $set);
        $validated = $this->normalizeJournalEntryReference($validated, $set);
        $validated = $this->normalizeQualityDefectReference($validated, $set);
        $validated = $this->normalizeAcceptanceScopeReference($validated, $set);
        $validated = $this->normalizeDocumentRelations($validated, $set);
        $validated = $this->validateDocumentProfile($validated);

        return $validated;
    }

    /**
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    private function validateDocumentProfile(array $validated): array
    {
        $documentType = (string) ($validated['document_type'] ?? '');
        $profile = $this->profileRegistry->require($documentType);
        $errors = [];

        if (($profile['requires_work_type'] ?? false) === true && empty($validated['work_type_id'])) {
            $errors['work_type_id'] = [trans_message('executive_documentation.errors.work_type_required')];
        }

        if (($profile['requires_journal_entry'] ?? false) === true && empty($validated['journal_entry_id'])) {
            $errors['journal_entry_id'] = [trans_message('executive_documentation.errors.journal_entry_required')];
        }

        foreach ($this->profileRegistry->missingRequiredFields($documentType, $validated['profile_data'] ?? []) as $fieldKey => $fieldLabel) {
            $errors["profile_data.{$fieldKey}"] = [trans_message('executive_documentation.errors.profile_field_required', ['field' => $fieldLabel])];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $validated;
    }

    /**
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    private function normalizeWorkTypeReference(array $validated, ExecutiveDocumentSet $set): array
    {
        $workTypeId = (int) ($validated['work_type_id'] ?? 0);

        if ($workTypeId <= 0) {
            return $validated;
        }

        $workType = WorkType::query()
            ->where('organization_id', $set->organization_id)
            ->where('category', 'Исполнительная документация')
            ->where('is_active', true)
            ->find($workTypeId);

        if ($workType === null) {
            throw ValidationException::withMessages([
                'work_type_id' => trans_message('executive_documentation.errors.work_type_not_found'),
            ]);
        }

        $validated['work_type_id'] = $workType->id;
        $validated['work_type_name'] = $workType->name;

        return $validated;
    }

    /**
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    private function normalizeJournalEntryReference(array $validated, ExecutiveDocumentSet $set): array
    {
        $entryId = (int) ($validated['journal_entry_id'] ?? 0);

        if ($entryId <= 0) {
            return $validated;
        }

        $entry = ConstructionJournalEntry::query()
            ->whereHas('journal', static fn ($query) => $query
                ->where('organization_id', $set->organization_id)
                ->where('project_id', $set->project_id))
            ->with('journal:id,name,journal_number')
            ->find($entryId);

        if ($entry === null) {
            throw ValidationException::withMessages([
                'journal_entry_id' => trans_message('executive_documentation.errors.journal_entry_not_found'),
            ]);
        }

        $profileData = is_array($validated['profile_data'] ?? null) ? $validated['profile_data'] : [];
        $profileData['journal_entry_id'] = $entry->id;
        $profileData['journal_entry_number'] = $entry->entry_number;
        $profileData['journal_entry_date'] = $entry->entry_date?->format('Y-m-d');
        $profileData['journal_name'] = $entry->journal?->name;
        $profileData['journal_number'] = $entry->journal?->journal_number;
        $profileData['work_description'] = $entry->work_description;
        $validated['profile_data'] = $profileData;

        return $validated;
    }

    /**
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    private function normalizeDocumentRelations(array $validated, ExecutiveDocumentSet $set): array
    {
        $relations = $validated['relations'] ?? [];

        if (!is_array($relations) || $relations === []) {
            return $validated;
        }

        $allowedTargets = $this->allowedRelationTargets((string) ($validated['document_type'] ?? ''));

        foreach ($relations as $index => $relation) {
            $relationType = (string) ($relation['relation_type'] ?? '');
            $targetType = (string) ($relation['target_type'] ?? '');
            $targetId = (int) ($relation['target_id'] ?? 0);

            if ($targetId <= 0) {
                continue;
            }

            $hasProfileRelation = array_key_exists($relationType, $allowedTargets);
            $expectedTarget = $allowedTargets[$relationType] ?? '';
            $targetMatchesProfile = $hasProfileRelation
                && (
                    $targetType === $expectedTarget
                    || ($expectedTarget === 'executive_document' && $this->profileRegistry->find($targetType) !== null)
                );
            $exists = $targetMatchesProfile && $this->relationTargetExists($targetType, $targetId, $set);

            if (!$exists) {
                throw ValidationException::withMessages([
                    "relations.{$index}.target_id" => trans_message('executive_documentation.errors.relation_target_not_found'),
                ]);
            }
        }

        return $validated;
    }

    /**
     * @return array<string, string>
     */
    private function allowedRelationTargets(string $documentType): array
    {
        $profile = $this->profileRegistry->find($documentType);

        if ($profile === null) {
            return [];
        }

        $targets = [];

        foreach ($profile['relations'] ?? [] as $relation) {
            $targets[(string) $relation['key']] = (string) $relation['target'];
        }

        foreach ($profile['fields'] ?? [] as $field) {
            if (($field['type'] ?? null) === 'relation' && isset($field['target'])) {
                $targets[(string) $field['key']] = (string) $field['target'];
            }
        }

        return $targets;
    }

    private function relationTargetExists(string $targetType, int $targetId, ExecutiveDocumentSet $set): bool
    {
        if ($targetType === 'journal_entry') {
            return ConstructionJournalEntry::query()
                ->whereHas('journal', static fn ($query) => $query
                    ->where('organization_id', $set->organization_id)
                    ->where('project_id', $set->project_id))
                ->whereKey($targetId)
                ->exists();
        }

        if ($targetType === 'material') {
            return Material::query()
                ->where('organization_id', $set->organization_id)
                ->where('is_active', true)
                ->whereKey($targetId)
                ->exists();
        }

        if ($targetType === 'supplier') {
            return Supplier::query()
                ->where('organization_id', $set->organization_id)
                ->where('is_active', true)
                ->whereKey($targetId)
                ->exists();
        }

        $query = ExecutiveDocument::query()
            ->where('organization_id', $set->organization_id)
            ->where('project_id', $set->project_id)
            ->whereKey($targetId);

        if ($targetType !== 'executive_document') {
            $query->where('document_type', $targetType);
        }

        return $query->exists();
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
