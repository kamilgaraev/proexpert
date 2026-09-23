<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Services;

use App\BusinessModules\Features\BasicWarehouse\Models\ProjectMaterialDelivery;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentRelation;
use App\BusinessModules\Features\ExecutiveDocumentation\Support\ExecutiveDocumentProfileRegistry;
use App\Models\ConstructionJournalEntry;
use App\Models\Material;
use App\Models\Supplier;
use Illuminate\Validation\ValidationException;

final class ExecutiveDocumentRelationSnapshot
{
    public function __construct(private readonly ExecutiveDocumentProfileRegistry $profiles) {}

    public function forDocument(ExecutiveDocument $document): array
    {
        $relations = $document->relations()->get([
            'id', 'organization_id', 'relation_type', 'target_type', 'target_id', 'label', 'metadata',
        ]);
        if ($relations->isEmpty()) {
            return [];
        }

        $allowedTargets = $this->allowedRelationTargets($document);
        foreach ($relations as $relation) {
            $expectedTarget = $allowedTargets[$relation->relation_type] ?? null;
            if ($expectedTarget !== $relation->target_type
                && ! ($relation->relation_type === 'quality_documents' && $expectedTarget === 'incoming_control_document' && in_array($relation->target_type, ['warehouse_passport', 'quality_passport'], true))
                && ! ($expectedTarget === 'executive_document' && $this->profiles->find($relation->target_type) !== null)) {
                $this->targetNotFound();
            }
        }

        $documentTargets = $relations->filter(fn (ExecutiveDocumentRelation $relation): bool => $this->isDocumentTarget($relation->target_type));
        $documents = $this->loadDocuments($document, $documentTargets);
        $versions = $this->loadVersions($documents->keyBy('id')->values()->all(), (int) $document->organization_id);
        $external = $this->loadExternalTargets($document, $relations->reject(fn (ExecutiveDocumentRelation $relation): bool => $this->isDocumentTarget($relation->target_type)));

        return $relations->map(function (ExecutiveDocumentRelation $relation) use ($document, $documents, $versions, $external): array {
            if ((int) $relation->organization_id !== (int) $document->organization_id) {
                $this->targetNotFound();
            }

            $key = $this->targetKey($relation->target_type, (int) $relation->target_id);
            $result = [
                'relation_type' => $relation->relation_type,
                'target_type' => $relation->target_type,
                'target_id' => (int) $relation->target_id,
                'label' => $relation->label,
                'metadata' => $relation->metadata,
                'target_version' => null,
                'domain_snapshot' => null,
            ];

            if ($this->isDocumentTarget($relation->target_type)) {
                $target = $documents[$key] ?? null;
                if ($target === null) {
                    $this->targetNotFound();
                }
                $version = $versions[$target->id] ?? null;
                $result['target_version'] = $version === null ? null : [
                    'version_id' => (int) $version->id,
                    'document_id' => (int) $target->id,
                    'content_hash' => $version->content_hash,
                    'status' => $this->scalarStatus($version->status),
                ];
                $result['document_snapshot'] = $version === null ? null : [
                    'title' => $version->basis_snapshot['document']['title'] ?? null,
                    'document_date' => $version->basis_snapshot['document']['document_date'] ?? null,
                    'number' => $version->profile_snapshot['act_number'] ?? $version->profile_snapshot['document_number'] ?? null,
                    'version_number' => $version->version_number,
                ];
            } else {
                $result['domain_snapshot'] = $external[$key] ?? $this->targetNotFound();
            }

            return $result;
        })->all();
    }

    private function isDocumentTarget(string $targetType): bool
    {
        return $targetType === 'executive_document' || $this->profiles->find($targetType) !== null;
    }

    private function loadDocuments(ExecutiveDocument $document, $relations)
    {
        $ids = $relations->pluck('target_id')->map(static fn ($id): int => (int) $id)->unique()->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        $targets = ExecutiveDocument::query()
            ->where('organization_id', $document->organization_id)
            ->where('project_id', $document->project_id)
            ->whereIn('id', $ids)
            ->get();
        $allowed = $relations->groupBy('target_id');

        return $targets->filter(function (ExecutiveDocument $target) use ($allowed): bool {
            return $allowed->get($target->id, collect())->contains(function (ExecutiveDocumentRelation $relation) use ($target): bool {
                return $relation->target_type === 'executive_document' || $relation->target_type === $target->document_type->value;
            });
        })->mapWithKeys(fn (ExecutiveDocument $target): array => [$this->targetKey($target->document_type->value, (int) $target->id) => $target] + [$this->targetKey('executive_document', (int) $target->id) => $target]);
    }

    private function loadVersions(array $documents, int $organizationId)
    {
        $ids = collect($documents)->pluck('id')->unique()->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        $latestIds = \App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentVersion::query()
            ->selectRaw('MAX(id)')
            ->where('organization_id', $organizationId)
            ->whereIn('document_id', $ids)
            ->groupBy('document_id');

        return \App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentVersion::query()
            ->where('organization_id', $organizationId)
            ->whereIn('id', $latestIds)
            ->orderByDesc('id')
            ->get()
            ->unique('document_id')
            ->keyBy('document_id');
    }

    private function allowedRelationTargets(ExecutiveDocument $document): array
    {
        $profile = $this->profiles->find($document->document_type->value);
        if ($profile === null) {
            $this->targetNotFound();
        }

        $targets = [];
        foreach ($profile['relations'] ?? [] as $relation) {
            if (is_array($relation) && isset($relation['key'], $relation['target'])) {
                $targets[(string) $relation['key']] = (string) $relation['target'];
            }
        }
        foreach ($profile['fields'] ?? [] as $field) {
            if (($field['type'] ?? null) === 'relation' && isset($field['key'], $field['target'])) {
                $targets[(string) $field['key']] = (string) $field['target'];
            }
        }

        return $targets;
    }

    private function loadExternalTargets(ExecutiveDocument $document, $relations): array
    {
        $result = [];
        foreach ($relations->groupBy('target_type') as $targetType => $items) {
            $ids = $items->pluck('target_id')->map(static fn ($id): int => (int) $id)->unique()->values();
            if ($targetType === 'warehouse_passport') {
                $movements = WarehouseMovement::query()
                    ->where('organization_id', $document->organization_id)
                    ->where(static fn ($query) => $query->where('project_id', $document->project_id)->orWhereNull('project_id'))
                    ->where('movement_type', WarehouseMovement::TYPE_RECEIPT)
                    ->whereHas('passportFile', static fn ($query) => $query->whereIn('id', $ids))
                    ->with(['passportFile', 'material:id,name'])
                    ->get();
                foreach ($movements as $movement) {
                    $file = $movement->passportFile;
                    $batchNumber = data_get($movement->metadata, 'batch_number');
                    $result[$this->targetKey($targetType, (int) $file->id)] = [
                        'id' => (int) $file->id,
                        'title' => 'Паспорт материала '.$movement->material?->name
                            .(is_string($batchNumber) && $batchNumber !== '' ? ', партия '.$batchNumber : '')
                            .' ('.$file->original_name.')',
                        'material_name' => $movement->material?->name,
                        'batch_number' => $batchNumber,
                        'movement_id' => (int) $movement->id,
                    ];
                }
                continue;
            }
            $targets = match ($targetType) {
                'journal_entry' => ConstructionJournalEntry::query()->whereHas('journal', static fn ($query) => $query
                    ->where('organization_id', $document->organization_id)->where('project_id', $document->project_id))->whereIn('id', $ids)->with('journal:id,name,journal_number')->get(),
                'material' => Material::query()->where('organization_id', $document->organization_id)->whereIn('id', $ids)->get(),
                'supplier' => Supplier::query()->where('organization_id', $document->organization_id)->whereIn('id', $ids)->get(),
                'project_material_delivery' => ProjectMaterialDelivery::query()->where('organization_id', $document->organization_id)->where('project_id', $document->project_id)->whereIn('id', $ids)->get(),
                default => collect(),
            };
            foreach ($targets as $target) {
                $result[$this->targetKey($targetType, (int) $target->id)] = $this->domainSnapshot($targetType, $target);
            }
        }

        return $result;
    }

    private function domainSnapshot(string $targetType, object $target): array
    {
        return match ($targetType) {
            'journal_entry' => [
                'id' => (int) $target->id, 'journal_id' => (int) $target->journal_id,
                'journal_name' => $target->journal?->name, 'journal_number' => $target->journal?->journal_number,
                'entry_number' => $target->entry_number, 'entry_date' => $target->entry_date?->format('Y-m-d'),
                'status' => $this->scalarStatus($target->status), 'work_description' => $target->work_description,
            ],
            'material' => ['id' => (int) $target->id, 'name' => $target->name, 'measurement_unit_id' => $target->measurement_unit_id, 'is_active' => (bool) $target->is_active],
            'supplier' => ['id' => (int) $target->id, 'name' => $target->name, 'inn' => $target->inn, 'ogrn' => $target->ogrn, 'is_active' => (bool) $target->is_active],
            'project_material_delivery' => [
                'id' => (int) $target->id, 'project_id' => (int) $target->project_id, 'material_id' => (int) $target->material_id,
                'status' => $this->scalarStatus($target->status), 'requested_quantity' => (string) $target->requested_quantity,
                'reserved_quantity' => (string) $target->reserved_quantity, 'shipped_quantity' => (string) $target->shipped_quantity,
                'accepted_quantity' => (string) $target->accepted_quantity, 'delivered_at' => $target->delivered_at?->toIso8601String(),
                'accepted_at' => $target->accepted_at?->toIso8601String(),
            ],
            default => $this->targetNotFound(),
        };
    }

    private function targetKey(string $type, int $id): string
    {
        return $type.':'.$id;
    }

    private function scalarStatus(mixed $status): ?string
    {
        return $status instanceof \BackedEnum ? (string) $status->value : ($status === null ? null : (string) $status);
    }

    private function targetNotFound(): never
    {
        throw ValidationException::withMessages([
            'relations' => trans_message('executive_documentation.errors.relation_target_not_found'),
        ]);
    }
}
