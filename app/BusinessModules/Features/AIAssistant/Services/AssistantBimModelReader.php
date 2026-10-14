<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\DesignManagement\Models\DesignSourceLink;
use App\BusinessModules\Features\DesignManagement\Services\DesignSourceLinkService;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final readonly class AssistantBimModelReader
{
    private const VERSION_FIELDS = ['id', 'project_id', 'artifact_id', 'title', 'version_number', 'source_original_name', 'is_current', 'updated_at'];
    private const ELEMENT_FIELDS = ['id', 'project_id', 'version_id', 'derivative_id', 'express_id', 'global_id', 'category', 'name', 'properties', 'classifications', 'updated_at'];
    private const CATEGORIES = [
        'перекрыти' => ['IFCSLAB'], 'плит' => ['IFCSLAB', 'IFCPLATE'], 'стен' => ['IFCWALL', 'IFCWALLSTANDARDCASE'],
        'колонн' => ['IFCCOLUMN'], 'балк' => ['IFCBEAM'], 'двер' => ['IFCDOOR'], 'окон' => ['IFCWINDOW'],
        'окн' => ['IFCWINDOW'], 'лестниц' => ['IFCSTAIR', 'IFCSTAIRFLIGHT'], 'кровл' => ['IFCROOF'],
        'крыш' => ['IFCROOF'], 'фундамент' => ['IFCFOOTING', 'IFCPILE'],
    ];

    public function __construct(private AssistantDataAccessPolicy $access, private DesignSourceLinkService $links,
        private AssistantDomainReadService $domainReader, private AssistantSourceReferenceGuard $references,
        private AssistantBimIndexQuery $index, private AssistantBimIndexProjection $projection,
        private AssistantBimModelLookup $lookup) {}

    public static function keywords(): array
    {
        return ['bim', 'ifc', 'бим', 'модель', 'кондиционер', 'вентилятор', 'насос', 'котёл', 'чиллер', 'оборудован', 'материал', 'свойств', 'объём', 'объем', 'классификац', ...array_keys(self::CATEGORIES)];
    }

    public function hasCurrentScopedAlias(User $actor, int $organizationId, int $projectId, string $alias): bool
    {
        $alias = trim($alias);
        if ($projectId < 1 || $alias === '' || mb_strlen($alias) > 200) {
            return false;
        }
        return $this->access->withCurrentChecks($actor, $organizationId, function () use ($actor, $organizationId, $projectId, $alias): bool {
            if (! $this->access->canReadDomain($actor, $organizationId, 'assistant')
                || ! $this->access->canCurrentPermission($actor, $organizationId, 'design-management.models.view')
                || ! $this->access->canReadEntity($actor, $organizationId, 'project', $projectId)) {
                return false;
            }
            $versions = $this->access->engineeringEntityQuery($actor, $organizationId, 'design_artifact_version');
            if ($versions === null) {
                return false;
            }
            $versions->where('design_artifact_versions.project_id', $projectId)->where('design_artifact_versions.is_current', true)
                ->where(static fn (Builder $source): Builder => $source->where('design_artifact_versions.source_format', 'ilike', 'ifc')
                    ->orWhere('design_artifact_versions.source_original_name', 'ilike', '%.ifc'));
            $this->lookup->apply($versions, $this->access->entityQuery($actor, $organizationId, 'design_artifact'),
                $this->access->entityQuery($actor, $organizationId, 'design_package'),
                $this->access->entityQuery($actor, $organizationId, 'project'), $alias);
            return $versions->select('design_artifact_versions.id')->exists();
        }, true);
    }

    public function read(User $actor, int $organizationId, array $filters): array
    {
        $filters = $this->filters($filters);
        $execution = app()->bound(AssistantRequestExecutionContext::class) ? app(AssistantRequestExecutionContext::class) : null;
        $operation = fn (): array => $this->access->withCurrentChecks($actor, $organizationId,
            fn (): array => $this->readCurrent($actor, $organizationId, $filters), true,
            $execution === null ? null : fn () => $execution->remainingMilliseconds());

        return $execution === null ? $operation() : $execution->withOperationBudget($operation, 12_000);
    }

    public function verifiedAnswer(array $result, User $actor, int $organizationId): ?array
    {
        $receipt = $result['bim_evidence'] ?? null;
        if (! is_array($receipt) || ($receipt['organization_id'] ?? null) !== $organizationId
            || ($receipt['actor_id'] ?? null) !== (int) $actor->id || ! is_array($receipt['filters'] ?? null)
            || ! is_string($receipt['version'] ?? null) || $receipt['version'] !== $this->version($receipt)) {
            return null;
        }
        $current = $this->read($actor, $organizationId, $receipt['filters']);
        if (! hash_equals($receipt['version'], $current['bim_evidence']['version'])) {
            return null;
        }

        return ['text' => $current['server_formatted_answer'], 'validation_status' => 'verified',
            'source_refs' => $current['source_refs'], 'replaced' => true,
            'needs_clarification' => $current['needs_clarification']];
    }

    private function readCurrent(User $actor, int $organizationId, array $filters): array
    {
        if (! $this->access->canReadDomain($actor, $organizationId, 'assistant')
            || ! $this->access->canCurrentPermission($actor, $organizationId, 'design-management.models.view')) {
            throw new AccessDeniedHttpException;
        }
        $versions = $this->access->engineeringEntityQuery($actor, $organizationId, 'design_artifact_version');
        $elements = $this->access->engineeringEntityQuery($actor, $organizationId, 'design_ifc_model_element');
        if ($versions === null || $elements === null) {
            throw new AccessDeniedHttpException;
        }
        if ($filters['project_id'] !== null) {
            if (! $this->access->canReadEntity($actor, $organizationId, 'project', $filters['project_id'])) {
                throw new AccessDeniedHttpException;
            }
            $versions->where('design_artifact_versions.project_id', $filters['project_id']);
        }
        if ($filters['compare_version_id'] !== null && $filters['version_id'] === null) {
            throw new \InvalidArgumentException('bim_comparison_requires_version_id');
        }
        if ($filters['version_id'] !== null) {
            $selected = (clone $versions)->whereKey($filters['version_id'])->first();
            if ($selected === null) {
                throw new AccessDeniedHttpException;
            }
            if ($filters['compare_version_id'] !== null) {
                $comparison = (clone $versions)->whereKey($filters['compare_version_id'])->first();
                if ($comparison === null || (int) $comparison->artifact_id !== (int) $selected->artifact_id
                    || (int) $comparison->project_id !== (int) $selected->project_id) {
                    throw new AccessDeniedHttpException;
                }
            }
            $versions->whereIn('design_artifact_versions.id', array_filter([$filters['version_id'], $filters['compare_version_id']]));
        } else {
            $versions->where('design_artifact_versions.is_current', true);
        }
        $versions->where(static fn (Builder $query): Builder => $query
            ->where('design_artifact_versions.source_format', 'ilike', 'ifc')
            ->orWhere('design_artifact_versions.source_original_name', 'ilike', '%.ifc'));
        $artifactScope = $this->access->entityQuery($actor, $organizationId, 'design_artifact');
        $packageScope = $this->access->entityQuery($actor, $organizationId, 'design_package');
        $projectScope = $this->access->entityQuery($actor, $organizationId, 'project');
        if ($filters['version_id'] === null) {
            $this->lookup->apply($versions, $artifactScope, $packageScope, $projectScope, $filters['model_query']);
        }
        $modelLimit = $filters['answer_kind'] === 'models' ? 100 : ($filters['equipment_kinds'] !== null || $filters['equipment_kind'] !== null ? 20 : 5);
        $candidates = $versions->select(array_map(static fn (string $field): string => 'design_artifact_versions.'.$field, self::VERSION_FIELDS))
            ->orderBy('design_artifact_versions.id')->limit($modelLimit + 1)->get();
        $models = [];
        $references = [];
        $fetchedAt = now()->toISOString();
        $tooMany = $candidates->count() > $modelLimit;
        $comparisonRows = [];
        [$sourceScopes, $parentReferences] = $this->sourceScopes($candidates->take($modelLimit), $artifactScope, $packageScope, $projectScope, $organizationId, $fetchedAt);
        array_push($references, ...$parentReferences);
        foreach ($candidates->take($modelLimit) as $candidate) {
            $reference = $this->reference('design_artifact_version', (int) $candidate->id, $organizationId,
                (int) $candidate->project_id, self::VERSION_FIELDS, (string) $candidate->getRawOriginal('updated_at'), $fetchedAt,
                $this->fieldIdentity($candidate->getAttributes(), self::VERSION_FIELDS));
            $references[] = $reference;
            $model = ['version' => $candidate->attributesToArray(), 'source_scope' => $sourceScopes[(int) $candidate->id] ?? [],
                'categories' => [], 'elements' => [], 'total' => null, 'has_more' => false,
                'estimate_link_coverage' => $filters['include_estimate_links'] ? 'page_limited' : 'not_requested'];
            if (! $tooMany && $filters['answer_kind'] !== 'models') {
                $scope = (clone $elements)->where('design_ifc_model_elements.project_id', $candidate->project_id)
                    ->where('design_ifc_model_elements.version_id', $candidate->id);
                $derivatives = $this->access->engineeringEntityQuery($actor, $organizationId, 'design_model_derivative');
                $derivative = $derivatives?->where('design_model_derivatives.version_id', $candidate->id)
                    ->where('design_model_derivatives.project_id', $candidate->project_id)
                    ->where('design_model_derivatives.status', 'ready')->where('design_model_derivatives.viewer_provider', 'thatopen')
                    ->where('design_model_derivatives.derivative_format', 'thatopen_frag')->orderByDesc('design_model_derivatives.id')->first();
                $schemaVersion = $derivative?->metadata['ifc_index_schema_version'] ?? null;
                $units = $schemaVersion === 2 ? $this->units($derivative?->metadata['ifc_units'] ?? []) : [];
                if ($derivative !== null) {
                    $scope->where('design_ifc_model_elements.derivative_id', $derivative->id);
                    $references[] = $this->reference('design_model_derivative', (int) $derivative->id, $organizationId,
                        (int) $candidate->project_id, ['id', 'project_id', 'version_id', 'status', 'viewer_provider', 'derivative_format', 'metadata'],
                        (string) $derivative->getRawOriginal('updated_at'), $fetchedAt,
                        $this->fieldIdentity($derivative->getAttributes(), ['id', 'project_id', 'version_id', 'status', 'viewer_provider', 'derivative_format', 'metadata']));
                }
                $scan = $this->index->read($scope, $filters, $this->categories($filters['element_query']), $units, $filters['compare_version_id'] !== null);
                $comparisonRows[(int) $candidate->id] = $scan['comparison_rows'];
                unset($scan['comparison_rows']);
                $model = array_replace($model, $scan);
                $expectedCount = $derivative?->metadata['indexed_element_count'] ?? null;
                $model['index_coverage'] = ['status' => $derivative !== null && is_int($expectedCount)
                    && $expectedCount === $scan['scanned_count'] ? 'complete' : 'unproven',
                    'derivative_id' => $derivative === null ? null : (int) $derivative->id,
                    'expected_count' => is_int($expectedCount) ? $expectedCount : null, 'indexed_count' => $scan['scanned_count'],
                    'scan_complete' => $scan['scan_complete'], 'scope' => 'indexed_elements', 'physical_equipment_total' => false];
                $semanticComplete = $model['index_coverage']['status'] === 'complete' && $schemaVersion === 2
                    && $scan['schema2_row_count'] === $scan['scanned_count'];
                $model['semantic_coverage'] = ['status' => $semanticComplete ? 'complete' : 'unproven',
                    'metadata_schema_version' => is_int($schemaVersion) ? $schemaVersion : null,
                    'schema2_row_count' => $scan['schema2_row_count'], 'indexed_count' => $scan['scanned_count'],
                    'fields_scope' => 'engineering_allowlist', 'physical_equipment_total' => false];
                $model['semantic_matching_count'] = $semanticComplete ? $model['total'] : null;
                if (! $semanticComplete) {
                    foreach ($model['quantity_totals'] as &$quantity) {
                        $quantity['sum'] = null;
                        $quantity['coverage'] = 'semantic_unproven';
                    }
                    unset($quantity);
                }
                $model['units'] = $units;
                $page = $model['elements'];
                $model['elements'] = [];
                $estimateLinks = [];
                if ($filters['include_estimate_links']) {
                    if ($this->access->canReadDomain($actor, $organizationId, 'estimates')
                        && $this->access->canCurrentPermission($actor, $organizationId, 'budget-estimates.finance.view')) {
                        [$estimateLinks, $linkReferences] = $this->estimateLinks($actor, $organizationId, (int) $candidate->project_id, (int) $candidate->id,
                            array_map(static fn (array $row): int => (int) $row['express_id'], $page));
                        array_push($references, ...$linkReferences);
                    } else {
                        $model['estimate_link_coverage'] = 'unavailable';
                    }
                }
                foreach ($page as $element) {
                    $fieldIdentity = $element['_source_field_identity'];
                    unset($element['_source_field_identity']);
                    $model['elements'][] = $element + ['estimate_links' => $estimateLinks[(int) $element['express_id']] ?? []];
                    $references[] = $this->reference('design_ifc_model_element', (int) $element['id'], $organizationId,
                        (int) $candidate->project_id, self::ELEMENT_FIELDS, (string) ($element['updated_at'] ?? ''), $fetchedAt, $fieldIdentity);
                }
            }
            $models[] = $model;
        }
        $comparison = null;
        if ($filters['compare_version_id'] !== null) {
            $complete = count($models) === 2 && count(array_filter($models, static fn (array $model): bool => ($model['index_coverage']['status'] ?? '') === 'complete'
                && ($model['semantic_coverage']['status'] ?? '') === 'complete' && $model['scan_complete'])) === 2;
            $comparison = $complete ? $this->projection->diff($comparisonRows[$filters['compare_version_id']] ?? [],
                $comparisonRows[$filters['version_id']] ?? [], $filters['limit']) : ['identity_complete' => false];
            $comparison['status'] = $complete && $comparison['identity_complete'] ? 'complete' : 'unavailable';
            if ($comparison['status'] !== 'complete') {
                unset($comparison['counts'], $comparison['changes']);
            }
            $comparison['before_version_id'] = $filters['compare_version_id'];
            $comparison['after_version_id'] = $filters['version_id'];
        }
        $receipt = ['organization_id' => $organizationId, 'actor_id' => (int) $actor->id,
            'filters' => $filters, 'models' => $models, 'too_many_models' => $tooMany, 'comparison' => $comparison];
        $receipt['version'] = $this->version($receipt);

        return ['status' => $models === [] ? 'empty' : 'success', 'models' => $models, 'comparison' => $comparison, 'bim_evidence' => $receipt,
            'source_refs' => $references, 'fetched_at' => $fetchedAt, 'validation_status' => 'verified',
            'server_summary_answer' => $this->summary($models, $filters, $tooMany),
            'needs_clarification' => $models === [] || $tooMany || ($comparison !== null && $comparison['status'] !== 'complete'),
            'server_formatted_answer' => $this->format($models, $filters, $tooMany).($comparison === null ? '' : "\n\n".trans_message(
                $comparison['status'] === 'complete' ? 'ai_assistant_bim.comparison' : 'ai_assistant_bim.comparison_unavailable', $comparison['counts'] ?? [])).$this->formatComparison($comparison)];
    }

    private function sourceScopes(\Illuminate\Support\Collection $versions, ?Builder $artifactScope, ?Builder $packageScope,
        ?Builder $projectScope, int $organizationId, string $fetchedAt): array
    {
        $artifactFields = ['id', 'project_id', 'package_id', 'title', 'updated_at'];
        $packageFields = ['id', 'project_id', 'title', 'updated_at'];
        $projectFields = ['id', 'name', 'updated_at'];
        $artifacts = $artifactScope === null ? collect() : (clone $artifactScope)->whereKey($versions->pluck('artifact_id')->unique()->all())
            ->select(array_map(static fn (string $field): string => 'design_artifacts.'.$field, $artifactFields))->get()->keyBy('id');
        $packages = $packageScope === null ? collect() : (clone $packageScope)->whereKey($artifacts->pluck('package_id')->filter()->unique()->all())
            ->select(array_map(static fn (string $field): string => 'design_packages.'.$field, $packageFields))->get()->keyBy('id');
        $projects = $projectScope === null ? collect() : (clone $projectScope)->whereKey($versions->pluck('project_id')->unique()->all())
            ->select(array_map(static fn (string $field): string => 'projects.'.$field, $projectFields))->get()->keyBy('id');
        $references = [];
        foreach ([['design_artifact', $artifacts, $artifactFields], ['design_package', $packages, $packageFields], ['project', $projects, $projectFields]] as [$type, $rows, $fields]) {
            foreach ($rows as $row) {
                $references[] = $this->reference($type, (int) $row->id, $organizationId,
                    $type === 'project' ? (int) $row->id : (int) $row->project_id, $fields,
                    (string) $row->getRawOriginal('updated_at'), $fetchedAt, $this->fieldIdentity($row->getAttributes(), $fields));
            }
        }
        $scopes = [];
        foreach ($versions as $version) {
            $artifact = $artifacts->get((int) $version->artifact_id);
            $package = $artifact === null ? null : $packages->get((int) $artifact->package_id);
            $project = $projects->get((int) $version->project_id);
            $scopes[(int) $version->id] = ['project' => ['id' => (int) $version->project_id, 'name' => $project?->name],
                'package' => $package === null ? null : ['id' => (int) $package->id, 'title' => $package->title],
                'artifact' => ['id' => (int) $version->artifact_id, 'title' => $artifact?->title],
                'version_selection' => $version->is_current ? 'current' : 'explicit_historical', 'query_scope' => 'authorized_parent_labels'];
        }
        return [$scopes, $references];
    }

    private function formatComparison(?array $comparison): string
    {
        if ($comparison === null || $comparison['status'] !== 'complete') {
            return '';
        }
        $lines = [];
        foreach ($comparison['changes'] as $change) {
            $lines[] = trans_message('ai_assistant_bim.comparison_element', ['id' => $this->text($change['global_id']),
                'kind' => trans_message('ai_assistant_bim.change_kinds.'.$change['kind'])]);
            if ($change['kind'] !== 'changed') {
                continue;
            }
            foreach (['name', 'category', 'properties', 'quantities', 'quantity_units', 'materials', 'classifications', 'attributes'] as $field) {
                $before = $change['before'][$field] ?? null;
                $after = $change['after'][$field] ?? null;
                if ($before === $after) {
                    continue;
                }
                if (is_array($before) || is_array($after)) {
                    foreach (array_unique([...array_keys(is_array($before) ? $before : []), ...array_keys(is_array($after) ? $after : [])]) as $key) {
                        $old = is_array($before) ? $before[$key] ?? null : null;
                        $new = is_array($after) ? $after[$key] ?? null : null;
                        if ($old !== $new) {
                            $lines[] = trans_message('ai_assistant_bim.comparison_value', ['field' => trans_message('ai_assistant_bim.comparison_fields.'.$field).(is_string($key) ? ' '.$this->text($key) : ''),
                                'before' => $this->text(is_scalar($old) ? (string) $old : json_encode($old, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
                                'after' => $this->text(is_scalar($new) ? (string) $new : json_encode($new, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))]);
                        }
                    }
                } else {
                    $lines[] = trans_message('ai_assistant_bim.comparison_value', ['field' => trans_message('ai_assistant_bim.comparison_fields.'.$field),
                        'before' => $this->text((string) $before), 'after' => $this->text((string) $after)]);
                }
            }
        }
        if ($comparison['has_more']) {
            $lines[] = trans_message('ai_assistant_bim.comparison_page', ['shown' => count($comparison['changes'])]);
        }
        return $lines === [] ? '' : "\n\n".implode("\n\n", $lines);
    }

    private function filters(array $filters): array
    {
        Validator::make($filters, ['model_query' => ['nullable', 'string', 'max:200'], 'element_query' => ['nullable', 'string', 'max:200'],
            'project_id' => ['nullable', 'integer', 'min:1'], 'version_id' => ['nullable', 'integer', 'min:1'],
            'compare_version_id' => ['nullable', 'integer', 'min:1', 'different:version_id'],
            'property_name' => ['nullable', 'string', 'max:100', 'required_with:property_value'], 'property_value' => ['nullable', 'string', 'max:200'],
            'material_query' => ['nullable', 'string', 'max:200'], 'classification_query' => ['nullable', 'string', 'max:200'],
            'storey_query' => ['nullable', 'string', 'max:200'], 'quantity_name' => ['nullable', 'string', 'max:100'],
            'equipment_kind' => ['nullable', 'string', 'in:air_conditioner,fan,pump,boiler,chiller,unknown'],
            'equipment_kinds' => ['nullable', 'array', 'min:1', 'max:6'],
            'equipment_kinds.*' => ['string', 'distinct', 'in:air_conditioner,fan,pump,boiler,chiller,ventilation_unit'],
            'offset' => ['integer', 'min:0', 'max:1000000'], 'limit' => ['integer', 'min:1', 'max:20'],
            'include_estimate_links' => ['boolean'], 'include_quantities' => ['boolean'], 'include_properties' => ['boolean'],
            'answer_kind' => ['string', 'in:elements,materials,models']])->validate();
        if (array_diff(array_keys($filters), ['model_query', 'element_query', 'project_id', 'version_id', 'compare_version_id', 'offset', 'limit', 'include_estimate_links',
            'property_name', 'property_value', 'material_query', 'classification_query', 'storey_query', 'quantity_name', 'equipment_kind', 'equipment_kinds', 'include_quantities', 'include_properties', 'answer_kind']) !== []) {
            throw new \InvalidArgumentException('unsupported_bim_model_argument');
        }
        if (! empty($filters['equipment_kind']) && ! empty($filters['equipment_kinds'])) {
            throw new \InvalidArgumentException('conflicting_bim_equipment_filters');
        }
        $equipmentKinds = $filters['equipment_kinds'] ?? [];
        sort($equipmentKinds);

        return ['answer_kind' => $filters['answer_kind'] ?? 'elements', 'model_query' => isset($filters['model_query']) ? trim($filters['model_query']) : null,
            'element_query' => isset($filters['element_query']) ? trim($filters['element_query']) : null,
            'project_id' => isset($filters['project_id']) ? (int) $filters['project_id'] : null,
            'version_id' => isset($filters['version_id']) ? (int) $filters['version_id'] : null,
            'compare_version_id' => isset($filters['compare_version_id']) ? (int) $filters['compare_version_id'] : null,
            'property_name' => isset($filters['property_name']) ? trim($filters['property_name']) : null,
            'property_value' => isset($filters['property_value']) ? trim($filters['property_value']) : null,
            'material_query' => isset($filters['material_query']) ? trim($filters['material_query']) : null,
            'classification_query' => isset($filters['classification_query']) ? trim($filters['classification_query']) : null,
            'storey_query' => isset($filters['storey_query']) ? trim($filters['storey_query']) : null,
            'quantity_name' => isset($filters['quantity_name']) ? trim($filters['quantity_name']) : null,
            'equipment_kind' => $filters['equipment_kind'] ?? null,
            'equipment_kinds' => $equipmentKinds === [] ? null : $equipmentKinds,
            'offset' => (int) ($filters['offset'] ?? 0), 'limit' => (int) ($filters['limit'] ?? 20),
            'include_estimate_links' => (bool) ($filters['include_estimate_links'] ?? false),
            'include_quantities' => ($filters['answer_kind'] ?? 'elements') !== 'materials' && (bool) ($filters['include_quantities'] ?? false),
            'include_properties' => (bool) ($filters['include_properties'] ?? false)];
    }

    private function units(mixed $units): array
    {
        if (! is_array($units)) {
            return [];
        }
        $result = [];
        foreach (array_slice($units, 0, 50) as $unit) {
            if (is_array($unit) && ($unit['source'] ?? null) === 'project_units_in_context') {
                $safe = [];
                foreach (['type', 'unit_type', 'name', 'prefix'] as $field) {
                    if (is_string($unit[$field] ?? null) && preg_match('/^[A-Z0-9_ ]{1,80}$/D', $unit[$field]) === 1) {
                        $safe[$field] = $unit[$field];
                    }
                }
                $result[] = $safe;
            }
        }
        return $result;
    }

    private function estimateLinks(User $actor, int $organizationId, int $projectId, int $versionId, array $elementIds): array
    {
        $byElement = [];
        $references = [];
        if ($elementIds === []) {
            return [$byElement, $references];
        }
        foreach ($this->links->linksForSource($actor, $organizationId, $versionId, $elementIds, 'estimate_item', 20) as $link) {
            $targetId = (int) $link['target']['id'];
            if (! $this->access->canReadEntity($actor, $organizationId, 'estimate_item', $targetId)) {
                continue;
            }
            $target = $this->domainReader->execute('read', ['domain' => 'estimates', 'entity_type' => 'estimate_item',
                'id' => $targetId, 'fields' => ['id', 'estimate_id', 'position_number', 'name']], $actor, $organizationId);
            $edge = $this->domainReader->execute('read', ['domain' => 'design_detail', 'entity_type' => 'design_source_link',
                'id' => (int) $link['id'], 'fields' => ['id', 'status', 'row_version', 'source_version_id', 'source_element_id']], $actor, $organizationId);
            if ($target['results'] === [] || $edge['results'] === []) {
                continue;
            }
            if (! DesignSourceLink::query()->where('organization_id', $organizationId)->where('project_id', $projectId)
                ->whereKey((int) $link['id'])->where('target_type', 'estimate_item')
                ->where('target_id', $targetId)->where('status', 'active')->where('row_version', (int) $link['revision'])
                ->where('source_version_id', $versionId)->where('source_element_id', (int) $link['source_element_id'])->exists()) {
                continue;
            }
            $targetReferences = $target['source_refs'];
            foreach ($targetReferences as &$reference) {
                $reference['project_id'] = $projectId;
                $reference['estimate_id'] = (int) $target['results'][0]['fields']['estimate_id'];
                $reference['required_permissions'][] = 'budget-estimates.finance.view';
            }
            unset($reference);
            $edgeReferences = $edge['source_refs'];
            foreach ($edgeReferences as &$reference) {
                $reference['source_link_identity'] = AssistantSourceReferenceIdentity::key($edge['results'][0]['fields']);
            }
            unset($reference);
            $linkReferences = [...$edgeReferences, ...$targetReferences];
            if (! $this->references->fresh($actor, $organizationId, $linkReferences)) {
                continue;
            }
            $byElement[(int) $link['source_element_id']][] = ['link_id' => (int) $link['id'], 'revision' => (int) $link['revision'],
                'source_version_id' => (int) $link['source_version_id'], 'source_element_id' => (int) $link['source_element_id'],
                'status' => $link['status'], 'target' => ['entity_type' => 'estimate_item'] + $target['results'][0]['fields']];
            array_push($references, ...$linkReferences);
        }

        return [$byElement, $references];
    }

    private function search(Builder $query, array $columns, ?string $term, bool $transliterate): void
    {
        if ($term === null || $term === '') {
            return;
        }
        $term = preg_replace('/\.ifc$/iu', '', $term) ?? $term;
        AssistantTextSearch::apply($query, $columns, $term, $transliterate);
    }

    private function categories(?string $term): array
    {
        $normalized = mb_strtolower($term ?? '');
        $categories = [];
        foreach (self::CATEGORIES as $stem => $types) {
            if (preg_match('/\b'.preg_quote($stem, '/').'\p{L}*/u', $normalized) === 1) {
                array_push($categories, ...$types);
            }
        }
        if (preg_match_all('/\bifc[a-z]+\b/i', $term ?? '', $matches)) {
            array_push($categories, ...array_map('strtoupper', $matches[0]));
        }

        return array_values(array_unique($categories));
    }

    private function version(array $receipt): string
    {
        return AssistantSourceReferenceIdentity::key(array_intersect_key($receipt,
            array_flip(['organization_id', 'actor_id', 'filters', 'models', 'too_many_models', 'comparison'])));
    }

    private function fieldIdentity(array $attributes, array $fields): string
    {
        return AssistantSourceReferenceIdentity::key(array_intersect_key($attributes, array_flip(array_diff($fields, ['updated_at']))));
    }

    private function reference(string $type, int $id, int $organizationId, int $projectId, array $fields, string $sourceVersion, string $fetchedAt, string $fieldIdentity): array
    {
        return ['entity_type' => $type, 'entity_id' => $id, 'organization_id' => $organizationId, 'project_id' => $projectId,
            'content_scope' => 'structured', 'checked_fields' => array_values(array_diff($fields, ['updated_at'])),
            'required_permissions' => $type === 'project' ? ['projects.view'] : ['design-management.view', 'design-management.models.view'],
            'required_domains' => [$type === 'project' ? 'projects' : (in_array($type, ['design_ifc_model_element', 'design_model_derivative'], true) ? 'design_detail' : 'design')],
            'source_version' => $sourceVersion, 'fetched_at' => $fetchedAt,
            'source_field_identity' => $fieldIdentity,
            'navigation' => ['url' => '/design-management']]
            + (in_array($type, ['design_artifact_version', 'design_ifc_model_element', 'design_model_derivative'], true) ? ['bim_projection' => 'engineering_index'] : []);
    }

    private function summary(array $models, array $filters, bool $tooMany): string
    {
        if (($filters['answer_kind'] ?? 'elements') === 'materials') { return $this->materialAnswer($models, $tooMany); }
        $complete = $models !== [] && ! $tooMany && ($filters['compare_version_id'] ?? null) === null;
        $sum = 0;
        $projects = [];
        $versions = [];
        $lines = [];
        $equipmentTotals = array_fill_keys($filters['equipment_kinds'] ?? [], 0);
        foreach ($models as $model) {
            $count = $model['semantic_matching_count'] ?? null;
            $covered = is_int($count) && $count >= 0 && ($model['scan_complete'] ?? false)
                && ($model['index_coverage']['status'] ?? null) === 'complete'
                && ($model['semantic_coverage']['status'] ?? null) === 'complete';
            $complete = $complete && $covered;
            foreach ($equipmentTotals as $kind => $total) {
                $value = $model['equipment_counts'][$kind] ?? null;
                if (! $covered || ! is_int($value) || $value < 0 || $total > PHP_INT_MAX - $value) {
                    $complete = false;
                } else {
                    $equipmentTotals[$kind] += $value;
                }
            }
            if ($covered && $sum <= PHP_INT_MAX - $count) { $sum += $count; } else { $complete = false; }
            $project = $model['version']['project_id'] ?? null;
            $version = $model['version']['id'] ?? null;
            if (! is_int($project) || ! is_int($version)) { $complete = false; }
            $projects[(string) $project] = true;
            $versions[(string) $version] = true;
            $name = $model['source_scope']['artifact']['title'] ?? $model['version']['title'] ?? '';
            $lines[] = trans_message($covered ? 'ai_assistant_bim.model_matching_count' : 'ai_assistant_bim.model_count_unproven',
                ['name' => $this->text((string) $name), 'count' => $count]);
        }
        if ($complete && count($projects) === 1 && count($versions) === count($models)) {
            array_unshift($lines, trans_message('ai_assistant_bim.matching_total', ['count' => $sum]));
            foreach ($equipmentTotals as $kind => $count) {
                array_unshift($lines, trans_message('ai_assistant_bim.equipment_matching_count',
                    ['name' => trans_message('ai_assistant_bim.equipment_labels.'.$kind), 'count' => $count]));
            }
        }
        return implode("\n", $lines);
    }

    private function format(array $models, array $filters, bool $tooMany): string
    {
        if ($filters['answer_kind'] === 'models') {
            $lines = [trans_message('ai_assistant_bim.model_catalog_scope', ['count' => count($models)])];
            foreach ($models as $model) {
                $version = $model['version'];
                $lines[] = trans_message('ai_assistant_bim.model_line', ['name' => $this->text($version['title'] ?: $version['source_original_name']),
                    'file' => $this->text($version['source_original_name']), 'version' => $this->text($version['version_number']), 'id' => $version['id']])
                    .' '.trans_message($version['is_current'] ? 'ai_assistant_bim.version_current' : 'ai_assistant_bim.version_historical');
            }
            if ($tooMany) { $lines[] = trans_message('ai_assistant_bim.model_catalog_partial'); }
            $lines[] = trans_message('ai_assistant_bim.model_catalog_basis');
            return implode("\n\n", $lines);
        }
        if (($filters['answer_kind'] ?? 'elements') === 'materials') { return $this->materialAnswer($models, $tooMany); }
        if ($models === []) {
            return trans_message('ai_assistant_bim.model_not_found');
        }
        $lines = [$this->summary($models, $filters, $tooMany),
            trans_message(count($models) > 1 ? 'ai_assistant_bim.multiple_models' : 'ai_assistant_bim.answer_header')];
        foreach ($models as $model) {
            $version = $model['version'];
            $lines[] = trans_message('ai_assistant_bim.model_line', ['name' => $this->text($version['title'] ?: $version['source_original_name']),
                'file' => $this->text($version['source_original_name']), 'version' => $this->text($version['version_number']), 'id' => $version['id']]);
            $scopeLabels = [];
            foreach (['project' => 'name', 'package' => 'title', 'artifact' => 'title'] as $source => $field) {
                $label = $model['source_scope'][$source][$field] ?? null;
                if (is_string($label) && $label !== '') {
                    $scopeLabels[] = trans_message('ai_assistant_bim.scope_labels.'.$source).': '.$this->text($label);
                }
            }
            if ($scopeLabels !== []) {
                $lines[] = implode('; ', $scopeLabels).'.';
            }
            if ($tooMany) {
                continue;
            }
            $lines[] = trans_message(($model['index_coverage']['status'] ?? 'unproven') === 'complete'
                ? 'ai_assistant_bim.index_complete' : 'ai_assistant_bim.index_unproven', ['count' => $model['scanned_count']]);
            if ($model['semantic_coverage']['status'] !== 'complete') {
                $lines[] = trans_message('ai_assistant_bim.semantic_unproven');
            }
            if (! $model['scan_complete']) {
                $lines[] = trans_message('ai_assistant_bim.scan_partial');
            }
            if ($model['categories'] === []) {
                $lines[] = trans_message('ai_assistant_bim.elements_unavailable');
                continue;
            }
            $elementQuery = $filters['element_query'] ?: ($filters['equipment_kind'] ?? null);
            if ($model['total'] !== null && $elementQuery !== null && $elementQuery !== '') {
                $lines[] = trans_message($model['total'] === 0 ? 'ai_assistant_bim.elements_not_found' : 'ai_assistant_bim.matches',
                    ['query' => $this->text($elementQuery), 'count' => $model['total']]);
            }
            $categories = array_map(fn (array $row): string => $this->categoryLabel($row['category']).': '.$row['count'], array_slice($model['categories'], 0, 100));
            $lines[] = trans_message('ai_assistant_bim.categories', ['categories' => implode('; ', $categories)]);
            if (count($model['categories']) > 100) {
                $lines[] = trans_message('ai_assistant_bim.categories_partial');
            }
            foreach ($model['elements'] as $element) {
                $lines[] = trans_message('ai_assistant_bim.element_line', ['name' => $this->text($element['name'] ?: '#'.$element['express_id']),
                    'category' => $this->categoryLabel($element['category']), 'id' => $element['express_id']]);
                $details = [];
                foreach ($element['properties'] as $path => $value) {
                    $details[] = $this->text($path).': '.$this->text((string) $value);
                }
                if ($details !== []) {
                    $lines[] = trans_message('ai_assistant_bim.properties_line', ['properties' => implode('; ', $details)]);
                }
                foreach ($element['attributes'] as $field => $value) {
                    $lines[] = trans_message('ai_assistant_bim.attribute_line', ['field' => trans_message('ai_assistant_bim.attribute_labels.'.$field), 'value' => $this->text((string) $value)]);
                }
                foreach (['materials', 'classifications'] as $field) {
                    if ($element[$field] !== []) {
                        $lines[] = trans_message('ai_assistant_bim.'.$field.'_line', ['values' => implode('; ', array_map($this->text(...), $element[$field]))]);
                    }
                }
                foreach ($element['estimate_links'] as $link) {
                    $target = $link['target'];
                    $lines[] = trans_message('ai_assistant_bim.estimate_link_line', ['element' => $element['express_id'],
                        'estimate' => $target['estimate_id'], 'position' => $this->text($target['position_number']),
                        'name' => $this->text($target['name']), 'id' => $target['id']]);
                }
            }
            foreach ($model['quantity_totals'] as $quantity) {
                $lines[] = trans_message($quantity['sum'] === null ? 'ai_assistant_bim.quantity_unavailable' : 'ai_assistant_bim.quantity_total',
                    ['name' => $this->text($quantity['name']), 'value' => $quantity['sum'], 'unit' => $this->text($quantity['unit']), 'count' => $quantity['elements']]);
            }
            if ($model['estimate_link_coverage'] !== 'not_requested') {
                $lines[] = trans_message($model['estimate_link_coverage'] === 'unavailable'
                    ? 'ai_assistant_bim.estimate_links_unavailable' : 'ai_assistant_bim.estimate_links_partial');
            }
            if ($model['has_more'] && $model['total'] !== null) {
                $lines[] = trans_message('ai_assistant_bim.page', ['shown' => count($model['elements']), 'total' => $model['total'],
                    'offset' => $filters['offset'], 'next' => $filters['offset'] + count($model['elements'])]);
            }
        }
        if ($tooMany) {
            $lines[] = trans_message($filters['equipment_kinds'] !== null || $filters['equipment_kind'] !== null
                ? 'ai_assistant_bim.too_many_equipment_models' : 'ai_assistant_bim.too_many_models');
        }

        return implode("\n\n", $lines);
    }

    private function materialAnswer(array $models, bool $tooMany): string
    {
        if ($models === []) { return trans_message('ai_assistant_bim.model_not_found'); }
        $lines = [trans_message('ai_assistant_bim.material_summary_intro')];
        foreach ($models as $model) {
            $name = $this->text((string) ($model['source_scope']['artifact']['title'] ?? $model['version']['title'] ?? ''));
            $summary = $model['material_summary'] ?? null;
            $covered = ! $tooMany && ($model['index_coverage']['status'] ?? null) === 'complete'
                && ($model['semantic_coverage']['status'] ?? null) === 'complete' && is_array($summary);
            if (! $covered) { $lines[] = trans_message('ai_assistant_bim.material_summary_unavailable', ['name' => $name]); continue; }
            $names = $summary['names'];
            $lines[] = trans_message('ai_assistant_bim.material_summary_model', ['name' => $name]);
            foreach ($names as $material) { $lines[] = '• '.$this->text($material); }
            if ($summary['coverage'] === 'full_matching_index' && $summary['total'] === 0) {
                $lines[] = trans_message('ai_assistant_bim.material_summary_empty');
            } elseif ($summary['coverage'] !== 'full_matching_index') {
                $lines[] = trans_message('ai_assistant_bim.material_summary_unconfirmed');
            } elseif ($summary['truncated']) {
                $lines[] = trans_message('ai_assistant_bim.material_summary_partial', ['shown' => count($names), 'total' => $summary['total']]);
            } elseif ($names === []) {
                $lines[] = trans_message('ai_assistant_bim.material_summary_finished', ['total' => $summary['total']]);
            }
        }

        return implode("\n", $lines);
    }

    private function categoryLabel(?string $category): string
    {
        $key = 'ai_assistant_bim.category_labels.'.($category ?? '');

        return \Illuminate\Support\Facades\Lang::has($key) ? trans_message($key) : $this->text($category ?? '');
    }

    private function text(?string $text): string
    {
        $bounded = mb_substr(preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', ' ', $text ?? '') ?? '', 0, 255);
        $escapes = ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;'];
        foreach (str_split('!"#$%\'()*+,-./:;=?@[\\]^_`{|}~') as $character) {
            $escapes[$character] = '\\'.$character;
        }

        return strtr($bounded, $escapes);
    }
}
