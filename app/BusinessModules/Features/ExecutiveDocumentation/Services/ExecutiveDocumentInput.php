<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Services;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\ExecutiveDocumentation\Support\ExecutiveDocumentProfileRegistry;
use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceScope;
use App\BusinessModules\Features\HandoverAcceptance\Models\ProjectLocation;
use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\Models\WorkType;
use App\Models\ConstructionJournalEntry;
use App\Models\CompletedWork;
use App\Models\Material;
use App\Models\Supplier;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Support\Facades\Validator;

final class ExecutiveDocumentInput
{
    public function __construct(private readonly ExecutiveDocumentProfileRegistry $profileRegistry, private readonly HiddenWorkActAutofillService $hiddenWorkActAutofillService) {}

    public function rules(): array
    {
        return [
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
                'initial_version.file' => ['required_without:source_warehouse_passport_file_id', File::types(['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png'])->max(25 * 1024)],
                'initial_version.version_number' => ['required_with:initial_version', 'string', 'max:40'],
                'initial_version.uploaded_at' => ['nullable', 'date'],
                'initial_version.file_kind' => ['nullable', Rule::in(['copy', 'paper_scan', 'electronic_original'])],
                'initial_version.signature_file' => ['nullable', 'file', 'max:25600'],
                'source_warehouse_passport_file_id' => ['required_without:initial_version.file', 'integer', 'min:1'],
                'metadata' => ['nullable', 'array'],
            ];
    }

    public function validateMapping(array $data, ExecutiveDocumentSet $set): array
    {
        $rules = $this->rules();
        unset($rules['initial_version'], $rules['initial_version.file'], $rules['initial_version.version_number'], $rules['initial_version.uploaded_at'], $rules['initial_version.file_kind'], $rules['initial_version.signature_file'], $rules['source_warehouse_passport_file_id']);
        unset($data['initial_version']);
        return $this->normalize(Validator::make($data, $rules)->validate(), $set);
    }

    public function normalize(array $validated, ExecutiveDocumentSet $set): array
    {
        $validated = $this->normalizeWorkTypeReference($validated, $set);
        $validated = $this->normalizeProjectLocationReference($validated, $set);
        $validated = $this->normalizeCompletedWorkReference($validated, $set);
        $validated = $this->hiddenWorkActAutofillService->applyToDocumentPayload($validated, $set);
        $validated = $this->normalizeJournalEntryReference($validated, $set);
        $validated = $this->normalizeQualityDefectReference($validated, $set);
        $validated = $this->normalizeAcceptanceScopeReference($validated, $set);
        $validated = $this->normalizeDocumentRelations($validated, $set);
        return $validated;
    }

    /**
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    public function validatePreparedProfile(array $validated): array
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

        foreach ($profile['relations'] ?? [] as $relation) {
            if (($relation['required'] ?? false) !== true) continue;
            $present = collect($validated['relations'] ?? [])->contains(fn (array $item): bool => ($item['relation_type'] ?? null) === $relation['key'] && (int) ($item['target_id'] ?? 0) > 0);
            if (!$present) $errors['relations.'.$relation['key']] = [trans_message('executive_documentation.errors.profile_field_required', ['field' => $relation['label'] ?? $relation['key']])];
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
        if ($targetType === 'project_material_delivery') {
            return \App\BusinessModules\Features\BasicWarehouse\Models\ProjectMaterialDelivery::query()
                ->where('organization_id', $set->organization_id)->where('project_id', $set->project_id)
                ->whereIn('status', ['partially_delivered', 'delivered', 'accepted', 'problem'])->whereKey($targetId)->exists();
        }
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

}
