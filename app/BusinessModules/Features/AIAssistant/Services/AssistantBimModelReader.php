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
    private const ELEMENT_FIELDS = ['id', 'project_id', 'version_id', 'express_id', 'global_id', 'category', 'name', 'updated_at'];
    private const CATEGORIES = [
        'перекрыти' => ['IFCSLAB'], 'плит' => ['IFCSLAB', 'IFCPLATE'], 'стен' => ['IFCWALL', 'IFCWALLSTANDARDCASE'],
        'колонн' => ['IFCCOLUMN'], 'балк' => ['IFCBEAM'], 'двер' => ['IFCDOOR'], 'окон' => ['IFCWINDOW'],
        'окн' => ['IFCWINDOW'], 'лестниц' => ['IFCSTAIR', 'IFCSTAIRFLIGHT'], 'кровл' => ['IFCROOF'],
        'крыш' => ['IFCROOF'], 'фундамент' => ['IFCFOOTING', 'IFCPILE'],
    ];

    public function __construct(private AssistantDataAccessPolicy $access, private DesignSourceLinkService $links,
        private AssistantDomainReadService $domainReader, private AssistantSourceReferenceGuard $references) {}

    public static function keywords(): array
    {
        return ['bim', 'ifc', 'бим', 'модель', ...array_keys(self::CATEGORIES)];
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
        $versions = $this->access->entityQuery($actor, $organizationId, 'design_artifact_version');
        $elements = $this->access->entityQuery($actor, $organizationId, 'design_ifc_model_element');
        if ($versions === null || $elements === null) {
            throw new AccessDeniedHttpException;
        }
        if ($filters['project_id'] !== null) {
            if (! $this->access->canReadEntity($actor, $organizationId, 'project', $filters['project_id'])) {
                throw new AccessDeniedHttpException;
            }
            $versions->where('design_artifact_versions.project_id', $filters['project_id']);
        }
        if ($filters['version_id'] !== null) {
            $versions->whereKey($filters['version_id']);
            if (! (clone $versions)->exists()) {
                throw new AccessDeniedHttpException;
            }
        } else {
            $versions->where('design_artifact_versions.is_current', true);
        }
        $versions->where(static fn (Builder $query): Builder => $query
            ->where('design_artifact_versions.source_format', 'ilike', 'ifc')
            ->orWhere('design_artifact_versions.source_original_name', 'ilike', '%.ifc'));
        $this->search($versions, ['design_artifact_versions.title', 'design_artifact_versions.source_original_name'], $filters['model_query'], true);
        $candidates = $versions->select(array_map(static fn (string $field): string => 'design_artifact_versions.'.$field, self::VERSION_FIELDS))
            ->orderBy('design_artifact_versions.id')->limit(6)->get();
        $models = [];
        $references = [];
        $fetchedAt = now()->toISOString();
        $tooMany = $candidates->count() > 5;
        foreach ($candidates->take(5) as $candidate) {
            $reference = $this->reference('design_artifact_version', (int) $candidate->id, $organizationId,
                (int) $candidate->project_id, self::VERSION_FIELDS, (string) $candidate->getRawOriginal('updated_at'), $fetchedAt);
            $references[] = $reference;
            $model = ['version' => $candidate->attributesToArray(), 'categories' => [], 'elements' => [], 'total' => null, 'has_more' => false,
                'estimate_link_coverage' => $filters['include_estimate_links'] ? 'page_limited' : 'not_requested'];
            if (! $tooMany) {
                $scope = (clone $elements)->where('design_ifc_model_elements.version_id', $candidate->id);
                $model['categories'] = (clone $scope)->select('design_ifc_model_elements.category')->selectRaw('COUNT(*) AS element_count')
                    ->groupBy('design_ifc_model_elements.category')->orderBy('design_ifc_model_elements.category')->limit(101)
                    ->get()->map(static fn ($row): array => ['category' => $row->category, 'count' => (int) $row->element_count])->all();
                $categories = $this->categories($filters['element_query']);
                if ($categories !== []) {
                    $scope->where(function (Builder $query) use ($categories, $filters): void {
                        $query->whereIn('design_ifc_model_elements.category', $categories);
                        $query->orWhere(function (Builder $names) use ($filters): void {
                            $this->search($names, ['design_ifc_model_elements.name'], $filters['element_query'], false);
                        });
                    });
                } else {
                    $this->search($scope, ['design_ifc_model_elements.name', 'design_ifc_model_elements.global_id'], $filters['element_query'], false);
                }
                $model['total'] = (clone $scope)->count();
                $page = $scope->select(array_map(static fn (string $field): string => 'design_ifc_model_elements.'.$field, self::ELEMENT_FIELDS))
                    ->orderBy('design_ifc_model_elements.id')->offset($filters['offset'])->limit($filters['limit'])->get();
                $estimateLinks = [];
                if ($filters['include_estimate_links']) {
                    if ($this->access->canReadDomain($actor, $organizationId, 'estimates')
                        && $this->access->canCurrentPermission($actor, $organizationId, 'budget-estimates.finance.view')) {
                        [$estimateLinks, $linkReferences] = $this->estimateLinks($actor, $organizationId, (int) $candidate->project_id, (int) $candidate->id,
                            $page->pluck('express_id')->map(static fn ($id): int => (int) $id)->all());
                        array_push($references, ...$linkReferences);
                    } else {
                        $model['estimate_link_coverage'] = 'unavailable';
                    }
                }
                foreach ($page as $element) {
                    $model['elements'][] = $element->attributesToArray() + ['estimate_links' => $estimateLinks[(int) $element->express_id] ?? []];
                    $references[] = $this->reference('design_ifc_model_element', (int) $element->id, $organizationId,
                        (int) $candidate->project_id, self::ELEMENT_FIELDS, (string) $element->getRawOriginal('updated_at'), $fetchedAt);
                }
                $model['has_more'] = $filters['offset'] + count($model['elements']) < $model['total'];
            }
            $models[] = $model;
        }
        $receipt = ['organization_id' => $organizationId, 'actor_id' => (int) $actor->id,
            'filters' => $filters, 'models' => $models, 'too_many_models' => $tooMany];
        $receipt['version'] = $this->version($receipt);

        return ['status' => $models === [] ? 'empty' : 'success', 'models' => $models, 'bim_evidence' => $receipt,
            'source_refs' => $references, 'fetched_at' => $fetchedAt, 'validation_status' => 'verified',
            'needs_clarification' => $models === [] || $tooMany,
            'server_formatted_answer' => $this->format($models, $filters, $tooMany)];
    }

    private function filters(array $filters): array
    {
        Validator::make($filters, ['model_query' => ['nullable', 'string', 'max:200'], 'element_query' => ['nullable', 'string', 'max:200'],
            'project_id' => ['nullable', 'integer', 'min:1'], 'version_id' => ['nullable', 'integer', 'min:1'],
            'offset' => ['integer', 'min:0', 'max:1000000'], 'limit' => ['integer', 'min:1', 'max:20'],
            'include_estimate_links' => ['boolean']])->validate();
        if (array_diff(array_keys($filters), ['model_query', 'element_query', 'project_id', 'version_id', 'offset', 'limit', 'include_estimate_links']) !== []) {
            throw new \InvalidArgumentException('unsupported_bim_model_argument');
        }

        return ['model_query' => isset($filters['model_query']) ? trim($filters['model_query']) : null,
            'element_query' => isset($filters['element_query']) ? trim($filters['element_query']) : null,
            'project_id' => isset($filters['project_id']) ? (int) $filters['project_id'] : null,
            'version_id' => isset($filters['version_id']) ? (int) $filters['version_id'] : null,
            'offset' => (int) ($filters['offset'] ?? 0), 'limit' => (int) ($filters['limit'] ?? 20),
            'include_estimate_links' => (bool) ($filters['include_estimate_links'] ?? false)];
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
            array_flip(['organization_id', 'actor_id', 'filters', 'models', 'too_many_models'])));
    }

    private function reference(string $type, int $id, int $organizationId, int $projectId, array $fields, string $sourceVersion, string $fetchedAt): array
    {
        return ['entity_type' => $type, 'entity_id' => $id, 'organization_id' => $organizationId, 'project_id' => $projectId,
            'content_scope' => 'structured', 'checked_fields' => array_values(array_diff($fields, ['updated_at'])),
            'required_permissions' => ['design-management.view', 'design-management.models.view'],
            'required_domains' => [$type === 'design_ifc_model_element' ? 'design_detail' : 'design'],
            'source_version' => $sourceVersion, 'fetched_at' => $fetchedAt,
            'navigation' => ['url' => '/design-management']];
    }

    private function format(array $models, array $filters, bool $tooMany): string
    {
        if ($models === []) {
            return trans_message('ai_assistant_bim.model_not_found');
        }
        $lines = [trans_message(count($models) > 1 ? 'ai_assistant_bim.multiple_models' : 'ai_assistant_bim.answer_header')];
        foreach ($models as $model) {
            $version = $model['version'];
            $lines[] = trans_message('ai_assistant_bim.model_line', ['name' => $this->text($version['title'] ?: $version['source_original_name']),
                'file' => $this->text($version['source_original_name']), 'version' => $this->text($version['version_number']), 'id' => $version['id']]);
            if ($tooMany) {
                continue;
            }
            if ($model['categories'] === []) {
                $lines[] = trans_message('ai_assistant_bim.elements_unavailable');
                continue;
            }
            if ($filters['element_query'] !== null && $filters['element_query'] !== '') {
                $lines[] = trans_message($model['total'] === 0 ? 'ai_assistant_bim.elements_not_found' : 'ai_assistant_bim.matches',
                    ['query' => $this->text($filters['element_query']), 'count' => $model['total']]);
            }
            $categories = array_map(fn (array $row): string => $this->categoryLabel($row['category']).': '.$row['count'], array_slice($model['categories'], 0, 100));
            $lines[] = trans_message('ai_assistant_bim.categories', ['categories' => implode('; ', $categories)]);
            if (count($model['categories']) > 100) {
                $lines[] = trans_message('ai_assistant_bim.categories_partial');
            }
            foreach ($model['elements'] as $element) {
                $lines[] = trans_message('ai_assistant_bim.element_line', ['name' => $this->text($element['name'] ?: '#'.$element['express_id']),
                    'category' => $this->categoryLabel($element['category']), 'id' => $element['express_id']]);
                foreach ($element['estimate_links'] as $link) {
                    $target = $link['target'];
                    $lines[] = trans_message('ai_assistant_bim.estimate_link_line', ['element' => $element['express_id'],
                        'estimate' => $target['estimate_id'], 'position' => $this->text($target['position_number']),
                        'name' => $this->text($target['name']), 'id' => $target['id']]);
                }
            }
            if ($model['estimate_link_coverage'] !== 'not_requested') {
                $lines[] = trans_message($model['estimate_link_coverage'] === 'unavailable'
                    ? 'ai_assistant_bim.estimate_links_unavailable' : 'ai_assistant_bim.estimate_links_partial');
            }
            if ($model['has_more']) {
                $lines[] = trans_message('ai_assistant_bim.page', ['shown' => count($model['elements']), 'total' => $model['total'],
                    'offset' => $filters['offset'], 'next' => $filters['offset'] + count($model['elements'])]);
            }
        }
        if ($tooMany) {
            $lines[] = trans_message('ai_assistant_bim.too_many_models');
        }

        return implode("\n\n", $lines);
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
