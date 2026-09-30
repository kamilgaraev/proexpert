<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class AssistantDomainReadService
{
    private array $columns = [];

    public function __construct(
        private readonly AssistantDomainCatalog $catalog,
        private readonly AssistantDataAccessPolicy $access,
        private readonly AuthorizationService $authorization,
        private readonly ?\App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantSalesParentProjection $projections = null
    ) {}

    public function execute(string $operation, array $arguments, User $actor, int $organizationId): array
    {
        return $this->access->withCurrentChecks($actor, $organizationId,
            fn (): array => $this->executeCurrent($operation, $arguments, $actor, $organizationId), true);
    }

    private function executeCurrent(string $operation, array $arguments, User $actor, int $organizationId): array
    {
        $definition = $this->catalog->definition((string) ($arguments['domain'] ?? ''));
        if ($definition === null || ! $this->catalog->supports($definition->domain, $operation)) {
            throw ValidationException::withMessages(['domain' => ['unsupported_domain']]);
        }
        $entityType = (string) ($arguments['entity_type'] ?? $definition->entityType);
        if (! in_array($entityType, $definition->entityTypes, true)) {
            throw ValidationException::withMessages(['entity_type' => ['unsupported_entity']]);
        }
        $projection = $arguments['projection'] ?? null;
        if ($projection !== null && ($operation !== 'read' || ! is_string($projection)
            || ! isset(AssistantExtendedDomainRegistry::values('parentProjectionDefinitions')[$entityType][$projection]))) {
            throw ValidationException::withMessages(['projection' => ['unsupported_parent_projection']]);
        }
        $policyDomain = match ($definition->domain) { 'works' => 'projects', 'acts' => 'contracts', default => $definition->domain };
        if (! $this->access->canReadDomain($actor, $organizationId, $policyDomain)) {
            throw new AccessDeniedHttpException();
        }
        foreach ($definition->permissions as $permission) {
            if (! $this->access->canCurrentPermission($actor, $organizationId, $permission)) {
                throw new AccessDeniedHttpException();
            }
        }
        foreach ((array) ($definition->entityPermissions[$entityType] ?? []) as $permission) {
            if (! $this->access->canCurrentPermission($actor, $organizationId, $permission)) { throw new AccessDeniedHttpException(); }
        }
        $fields = $arguments['fields'] ?? $definition->fields;
        if (! is_array($fields) || array_filter($fields, static fn ($field): bool => ! is_string($field)) !== [] || array_diff($fields, $definition->fields) !== []) {
            throw ValidationException::withMessages(['fields' => ['unsupported_field']]);
        }
        $fields = array_values(array_filter($fields, function (string $field) use ($definition, $actor, $organizationId): bool {
            foreach ((array) ($definition->fieldPermissions[$field] ?? []) as $permission) {
                if (! $this->access->canCurrentPermission($actor, $organizationId, $permission)) { return false; }
            }
            return true;
        }));
        $query = $this->access->entityQuery($actor, $organizationId, $entityType);
        if ($query === null) {
            throw new AccessDeniedHttpException();
        }
        $table = $query->getModel()->getTable();
        $columns = $this->columns[$table] ??= \Illuminate\Support\Facades\Schema::getColumnListing($table);
        $safeColumns = AssistantExtendedDomainRegistry::values('safeSelectColumns')[$entityType] ?? $columns;
        $selected = self::projectedColumns($operation, $fields, $columns, $safeColumns,
            AssistantExtendedDomainRegistry::values('versionColumns')[$entityType] ?? [], $query->getModel()->getKeyName());
        $query->select(array_map(static fn (string $column): string => $table.'.'.$column, $selected));
        if ($operation === 'search') {
            $term = trim((string) ($arguments['query'] ?? ''));
            if (mb_strlen($term) > 200) {
                throw ValidationException::withMessages(['query' => ['query_too_long']]);
            }
            $columns = array_values(array_intersect($fields, $query->getModel()->getFillable(), ['name', 'title', 'number', 'address', 'description', 'subject', 'document_number', 'order_number', 'asset_code', 'worker_name', 'slug']));
            if ($term !== '') {
                if ($columns === []) {
                    return ['results' => [], 'source_refs' => [], 'fetched_at' => now()->toISOString()];
                }
                $query->where(static function (Builder $search) use ($columns, $term, $table): void {
                    foreach ($columns as $column) {
                        $search->orWhere($table.'.'.$column, 'ilike', '%'.addcslashes($term, '%_\\').'%');
                    }
                });
            }
            if (is_numeric($arguments['project_id'] ?? null)) {
                if (! \Illuminate\Support\Facades\Schema::hasColumn($table, 'project_id')) {
                    throw ValidationException::withMessages(['project_id' => ['unsupported_project_filter']]);
                }
                $query->where($table.'.project_id', (int) $arguments['project_id']);
            }
            $models = $query->orderBy($query->getModel()->getQualifiedKeyName())->limit(max(1, min(20, (int) ($arguments['limit'] ?? 5))))->get();
        } else {
            $id = $arguments['id'] ?? null;
            $idSchema = $definition->schemas[$operation]['properties']['id'] ?? [];
            $uuidAllowed = in_array('string', (array) ($idSchema['type'] ?? []), true);
            $identifierKind = AssistantExtendedDomainRegistry::values('identifierKinds')[$entityType]
                ?? ($query->getModel()->getKeyType() === 'int' ? 'integer' : 'string');
            if ($identifierKind === 'integer' && is_string($id) && preg_match('/^[1-9][0-9]*$/D', $id) === 1) {
                $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            }
            $validId = match ($identifierKind) {
                'integer' => is_int($id) && $id > 0,
                'uuid' => $uuidAllowed && is_string($id) && \Illuminate\Support\Str::isUuid($id),
                'ulid' => $uuidAllowed && is_string($id) && preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $id) === 1,
                'string' => $uuidAllowed && is_string($id) && (\Illuminate\Support\Str::isUuid($id) || preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $id) === 1),
                default => false,
            };
            if (! $validId) {
                throw ValidationException::withMessages(['id' => ['invalid_entity_id']]);
            }
            $models = $query->whereKey($id)->get();
        }
        $projectionParent = $models->first();
        if ($projection !== null && $projectionParent instanceof Model) {
            foreach (['projection_offset', 'projection_limit'] as $argument) {
                if (isset($arguments[$argument]) && ! is_int($arguments[$argument])) {
                    throw ValidationException::withMessages([$argument => ['invalid_projection_pagination']]);
                }
            }
            return ($this->projections ?? new \App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantSalesParentProjection($this->access, $this->authorization))
                ->read($actor, $organizationId, $projectionParent, $entityType, $projection,
                    $arguments['projection_offset'] ?? 0, $arguments['projection_limit'] ?? 20);
        }
        $results = [];
        $references = [];
        $numericRows = [];
        $factRows = [];
        $attachmentMessages = [];
        $fetchedAt = now()->toISOString();
        foreach ($models as $model) {
            $returnedFields = $operation === 'navigation' ? ['id'] : array_keys(array_intersect_key($model->attributesToArray(), array_flip($fields)));
            $reference = $this->reference($actor, $model, $definition, $entityType, $organizationId, $returnedFields, $fetchedAt);
            $references[] = $reference;
            $results[] = $operation === 'navigation'
                ? $reference
                : ['entity_type' => $entityType, 'id' => $model->getKey(), 'fields' => array_intersect_key($model->attributesToArray(), array_flip($fields)), 'navigation' => $reference['navigation']];
            $coverage = AssistantExtendedDomainRegistry::values('attachmentCoverageDefinitions')[$entityType] ?? null;
            if ($operation !== 'navigation' && is_array($coverage)) {
                $results[array_key_last($results)]['attachment_coverage'] = $coverage + ['source_ref' => $reference];
                $attachmentMessages[] = (string) ($coverage['message'] ?? '');
            }
            if ($operation !== 'navigation') {
                $row = AssistantDomainNumericEvidence::row($model, $entityType, $returnedFields, $reference);
                if ($row !== null) {
                    $numericRows[] = $row;
                }
                $factRow = AssistantStructuredFactFormatter::row($model, $entityType, $returnedFields, $reference);
                if ($factRow !== null) {
                    $factRows[] = $factRow;
                }
            }
        }
        $payload = ['results' => $results, 'source_refs' => $references, 'fetched_at' => $fetchedAt]
            + AssistantDomainNumericEvidence::payload($numericRows, $fetchedAt)
            + AssistantStructuredFactFormatter::payload($factRows, $fetchedAt);
        $retrievalCoverage = AssistantExtendedDomainRegistry::values('retrievalCoverageDefinitions')[$entityType] ?? null;
        if (is_array($retrievalCoverage)) {
            $payload['retrieval_coverage'] = array_merge($retrievalCoverage, ['mode' => AssistantExtendedDomainRegistry::retrievalMode($entityType),
                'source_type' => $definition->sourceType, 'entity_type' => $entityType]);
        }
        if ($attachmentMessages !== []) {
            $payload['server_formatted_facts'] = trim(($payload['server_formatted_facts'] ?? '')."\n".implode("\n", array_unique($attachmentMessages)));
        }

        return $payload;
    }

    private static function projectedColumns(string $operation, array $fields, array $columns, array $safeColumns, array $versionColumns, string $key): array
    {
        $requested = $operation === 'navigation' ? [] : array_intersect($fields, $safeColumns);
        $availableVersionColumns = array_intersect(['slug', 'updated_at', ...$versionColumns], $safeColumns);
        return array_values(array_intersect(array_unique([$key, 'project_id', ...$availableVersionColumns, ...$requested]), $columns));
    }

    private function reference(User $actor, Model $model, AssistantDomainDefinition $definition, string $entityType, int $organizationId, array $fields, string $fetchedAt): array
    {
        $template = AssistantExtendedDomainRegistry::values('navigationTemplates')[$entityType] ?? match ($entityType) {
            'crm_deal' => '/crm/deals/{id}',
            'crm_company' => '/crm/companies/{id}',
            'crm_lead' => '/crm/leads',
            'crm_contact' => '/crm/contacts',
            'purchase_request' => '/procurement/purchase-requests/{id}',
            'purchase_order' => '/procurement/purchase-orders/{id}',
            'supplier_proposal' => '/procurement/proposals/{id}',
            'supplier_request' => '/procurement/supplier-requests',
            'quality_defect' => '/quality-control/defects/{id}',
            'executive_document_set' => '/executive-documentation/sets/{id}',
            'design_package' => '/design-management/packages/{id}',
            'design_artifact', 'design_artifact_version', 'design_review_comment', 'design_model_set' => '/design-management',
            default => $definition->navigation,
        };
        $url = str_replace(['{id}', '{slug}', '{project_id}'], [(string) $model->getKey(), rawurlencode((string) $model->getAttribute('slug')), rawurlencode((string) $model->getAttribute('project_id'))], $template);
        if (in_array($entityType, ['estimate_item', 'estimate_section', 'estimate_item_resource'], true)) {
            $estimateId = $model->getAttribute('estimate_id') ?? $model->getRelationValue('item')?->estimate_id;
            $url = '/estimates/'.rawurlencode((string) $estimateId);
            $template = '/estimates';
        }
        if ($entityType === 'executive_document' && is_numeric($model->getAttribute('document_set_id'))) {
            $url = '/executive-documentation/sets/'.(int) $model->getAttribute('document_set_id');
            $template = '/executive-documentation';
        }
        if ($definition->domain === 'advance_accounting' && (! $this->access->canReadDomain($actor, $organizationId, 'finance')
            || ! $this->access->canCurrentPermission($actor, $organizationId, 'payments.invoice.view'))) {
            $url = '';
        }
        if (str_contains($template, '{project_id}') && $model->getAttribute('project_id') === null) { $url = ''; }
        $requiredPermissions = $definition->permissions;
        if (isset($definition->entityPermissions[$entityType])) {
            $requiredPermissions = array_merge($requiredPermissions, (array) $definition->entityPermissions[$entityType]);
        }
        foreach ($fields as $field) {
            if (isset($definition->fieldPermissions[$field])) {
                $requiredPermissions = array_merge($requiredPermissions, (array) $definition->fieldPermissions[$field]);
            }
        }
        $policyDomain = match ($definition->domain) { 'works' => 'projects', 'acts' => 'contracts', default => $definition->domain };
        $version = $model->getRawOriginal('updated_at') ?? AssistantSourceReferenceIdentity::key(array_intersect_key($model->getAttributes(), array_flip($fields)));
        return ['organization_id' => $organizationId, 'source_type' => $definition->sourceType, 'entity_type' => $entityType, 'entity_id' => $model->getKey(),
            'project_id' => $model->getAttribute('project_id'), 'navigation' => ['url' => $url], 'content_scope' => 'structured',
            'checked_fields' => array_values(array_unique($fields)), 'required_permissions' => array_values(array_unique($requiredPermissions)),
            'required_domains' => [$policyDomain], 'source_version' => (string) $version, 'fetched_at' => $fetchedAt];
    }
}
