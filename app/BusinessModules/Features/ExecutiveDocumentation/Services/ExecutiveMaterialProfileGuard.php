<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Services;

use App\BusinessModules\Features\BasicWarehouse\Models\ProjectMaterialDelivery;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument;
use App\BusinessModules\Features\ExecutiveDocumentation\Support\ExecutiveDocumentProfileRegistry;
use DomainException;

final class ExecutiveMaterialProfileGuard
{
    public function __construct(private readonly ExecutiveDocumentProfileRegistry $profiles) {}

    public function deliveryReferences(int $organizationId, int $projectId): array
    {
        return ProjectMaterialDelivery::query()->where('organization_id', $organizationId)->where('project_id', $projectId)
            ->whereIn('status', ['partially_delivered', 'delivered', 'accepted', 'problem'])
            ->with('material:id,name,measurement_unit_id', 'material.measurementUnit:id,short_name')
            ->latest('id')->limit(200)->get()->map(fn ($delivery) => [
                'id' => $delivery->id, 'material_id' => $delivery->material_id, 'material_name' => $delivery->material?->name,
                'quantity' => $delivery->accepted_quantity, 'unit' => $delivery->material?->measurementUnit?->short_name,
                'delivered_at' => $delivery->delivered_at?->format('Y-m-d'), 'status' => $delivery->status->value,
            ])->all();
    }

    public function snapshot(ExecutiveDocument $document): ?array
    {
        if ($document->document_type->value !== 'incoming_batch_control') return null;
        $id = $document->relations()->where('relation_type', 'material_delivery')->where('target_type', 'project_material_delivery')->value('target_id');
        $delivery = ProjectMaterialDelivery::query()->where('organization_id', $document->organization_id)
            ->where('project_id', $document->project_id)->with('material.measurementUnit')->find($id);
        if ($delivery === null) return null;
        return [
            'id' => $delivery->id, 'material_id' => $delivery->material_id, 'material_name' => $delivery->material?->name,
            'measurement_unit_id' => $delivery->material?->measurement_unit_id, 'unit' => $delivery->material?->measurementUnit?->short_name,
            'accepted_quantity' => $delivery->accepted_quantity, 'shipped_quantity' => $delivery->shipped_quantity,
            'delivered_at' => $delivery->delivered_at?->toIso8601String(),
            'quality_documents' => $document->relations()->where('target_type', 'quality_passport')->get()->map(function ($relation) use ($document) {
                $source = ExecutiveDocument::query()->where('organization_id', $document->organization_id)->where('project_id', $document->project_id)->find($relation->target_id);
                $version = $source?->versions()->first();
                return ['document_id' => $source?->id, 'version_id' => $version?->id, 'content_hash' => $version?->content_hash];
            })->all(),
        ];
    }

    public function assertValid(ExecutiveDocument $document): void
    {
        $type = $document->document_type->value;
        $data = $document->profile_data ?? [];
        if (in_array($type, ['system_test_act', 'inspection_result'], true)) {
            $hasValue = isset($data['measured_value']) && $data['measured_value'] !== '';
            $hasUnit = isset($data['measurement_unit']) && is_string($data['measurement_unit']) && trim($data['measurement_unit']) !== '';
            if ($hasValue !== $hasUnit || ($hasValue && !is_numeric($data['measured_value']))) {
                throw new DomainException(trans_message('executive_documentation.errors.measurement_unit_required'));
            }
        }
        if (!in_array($type, ['quality_passport', 'incoming_batch_control'], true)) return;
        if (($document->metadata['capture_mode'] ?? null) === 'uploaded') {
            $this->assertRelations($document);
            return;
        }
        $this->assertRelations($document);
        if ($this->profiles->validateProfileData($type, $data) !== []) {
            throw new DomainException(trans_message('executive_documentation.errors.profile_data_invalid'));
        }
        if (isset($data['valid_until']) && $data['valid_until'] !== '' && $data['valid_until'] < $data['quality_document_date']) {
            throw new DomainException(trans_message('executive_documentation.errors.profile_data_invalid'));
        }
        if ($type === 'quality_passport') return;
        $relations = $document->relations()->get();
        $deliveries = $relations->where('relation_type', 'material_delivery')->where('target_type', 'project_material_delivery');
        if ($deliveries->count() !== 1) {
            throw new DomainException(trans_message('executive_documentation.errors.control_delivery_required'));
        }
        $delivery = ProjectMaterialDelivery::query()->where('organization_id', $document->organization_id)
            ->where('project_id', $document->project_id)->whereKey($deliveries->first()->target_id)
            ->whereIn('status', ['partially_delivered', 'delivered', 'accepted', 'problem'])->first();
        if ($delivery === null || $data['checked_at'] < $data['received_at']) {
            throw new DomainException(trans_message('executive_documentation.errors.control_delivery_required'));
        }
        $quantity = $data['quantity'] ?? null;
        if (!is_scalar($quantity) || !preg_match('/^\d{1,12}(?:\.\d{1,3})?$/D', (string) $quantity) || (float) $quantity <= 0) {
            throw new DomainException(trans_message('executive_documentation.errors.profile_data_invalid'));
        }
        if (($data['control_result'] ?? '') !== 'accepted' && trim((string) ($data['quality_remarks'] ?? '')) === '') {
            throw new DomainException(trans_message('executive_documentation.errors.control_reason_required'));
        }
        if ($data['control_result'] !== 'rejected' && $relations->where('relation_type', 'quality_passport')->isEmpty()) {
            throw new DomainException(trans_message('executive_documentation.errors.control_quality_required'));
        }
        foreach ($relations->where('relation_type', 'quality_passport') as $relation) {
            $source = ExecutiveDocument::query()->where('organization_id', $document->organization_id)
                ->where('project_id', $document->project_id)->where('document_type', 'quality_passport')->find($relation->target_id);
            if ($relation->target_type !== 'quality_passport' || $source === null) {
                throw new DomainException(trans_message('executive_documentation.errors.relation_target_not_found'));
            }
            if (empty($source->versions()->first()?->content_hash)) {
                throw new DomainException(trans_message('executive_documentation.errors.control_quality_required'));
            }
            $materials = $source->relations()->where('target_type', 'material')->pluck('target_id');
            if ($materials->isNotEmpty() && !$materials->contains($delivery->material_id)) {
                throw new DomainException(trans_message('executive_documentation.errors.control_material_mismatch'));
            }
        }
    }

    private function assertRelations(ExecutiveDocument $document): void
    {
        $definitions = collect($this->profiles->require($document->document_type->value)['relations'])->keyBy('key');
        foreach ($document->relations()->get() as $relation) {
            $definition = $definitions[$relation->relation_type] ?? null;
            if ($definition === null || $definition['target'] !== $relation->target_type || $relation->target_id <= 0) {
                throw new DomainException(trans_message('executive_documentation.errors.relation_target_not_found'));
            }
            $query = match ($relation->target_type) {
                'material' => \App\Models\Material::query()->where('is_active', true),
                'supplier' => \App\Models\Supplier::query()->where('is_active', true),
                'project_material_delivery' => ProjectMaterialDelivery::query()->where('project_id', $document->project_id),
                default => ExecutiveDocument::query()->where('project_id', $document->project_id)
                    ->when($relation->target_type !== 'executive_document', fn ($query) => $query->where('document_type', $relation->target_type)),
            };
            if (!$query->where('organization_id', $document->organization_id)->whereKey($relation->target_id)->exists()) {
                throw new DomainException(trans_message('executive_documentation.errors.relation_target_not_found'));
            }
        }
    }
}
