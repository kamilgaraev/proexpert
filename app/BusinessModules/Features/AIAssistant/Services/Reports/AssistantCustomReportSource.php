<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainCatalog;
use App\BusinessModules\Features\AIAssistant\Services\AssistantExtendedDomainRegistry;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final readonly class AssistantCustomReportSource
{
    public function __construct(private AssistantDomainCatalog $catalog, private AssistantDataAccessPolicy $policy) {}

    public function resolve(User $actor, int $organizationId, string $domain, string $entityType, ?int $projectId): array
    {
        $definition = $this->catalog->definition($domain);
        if ($definition === null || ! in_array($entityType, $definition->entityTypes, true)) {
            throw ValidationException::withMessages(['source' => trans_message('ai_custom_reports.unsupported_source')]);
        }
        $policyDomain = match ($domain) {
            'works' => 'projects', 'acts' => 'contracts', default => $domain
        };
        if (! $this->policy->belongsToOrganization($actor, $organizationId)
            || ! $this->policy->canReadDomain($actor, $organizationId, $policyDomain)) {
            throw new AccessDeniedHttpException;
        }
        foreach ([...$definition->permissions, ...(array) ($definition->entityPermissions[$entityType] ?? [])] as $permission) {
            if (! $this->policy->canCurrentPermission($actor, $organizationId, $permission)) {
                throw new AccessDeniedHttpException;
            }
        }
        $members = $domain === 'people' && $entityType === 'user' ? app(AssistantOrganizationMemberReportProjection::class) : null;
        $query = $members?->query($actor, $organizationId, $this->policy)
            ?? $this->policy->entityQuery($actor, $organizationId, $entityType);
        if (! $query instanceof Builder) {
            throw new AccessDeniedHttpException;
        }
        if ($entityType === 'contract') {
            (new \App\BusinessModules\Features\AIAssistant\Services\AssistantContractOrganizationScope)->apply($query, $organizationId, true);
        }
        $projection = $entityType === 'machinery_asset' ? new AssistantMachineryReportProjection($this->policy) : null;
        if ($projection !== null) {
            $query = $projection->scope($query, $actor, $organizationId, $projectId);
        } elseif ($projectId !== null && ! $this->policy->applyEntityProjectScope($actor, $organizationId, $entityType, $query, $projectId)) {
            throw ValidationException::withMessages(['project_id' => trans_message('ai_custom_reports.unsupported_project')]);
        }
        $model = $query->getModel();
        $table = $model->getTable();
        $columnTypes = array_column(Schema::getColumns($table), 'type_name', 'name');
        $safe = AssistantExtendedDomainRegistry::values('safeSelectColumns')[$entityType] ?? array_keys($columnTypes);
        $fields = [];
        $labels = AssistantExtendedDomainRegistry::values('fieldLabels');
        foreach (array_intersect($definition->fields, $safe, array_keys($columnTypes)) as $field) {
            if (preg_match('/^[a-z][a-z0-9_]*$/D', $field) !== 1) {
                continue;
            }
            foreach ((array) ($definition->fieldPermissions[$field] ?? []) as $permission) {
                if (! $this->policy->canCurrentPermission($actor, $organizationId, $permission)) {
                    continue 2;
                }
            }
            $databaseType = $columnTypes[$field];
            $type = match (true) {
                in_array($databaseType, ['smallint', 'integer', 'bigint', 'int2', 'int4', 'int8', 'numeric', 'decimal', 'float4', 'float8', 'double precision', 'real'], true) => 'number',
                in_array($databaseType, ['bool', 'boolean'], true) => 'boolean',
                in_array($databaseType, ['date', 'timestamp', 'timestamptz', 'timestamp without time zone', 'timestamp with time zone'], true) => 'date',
                in_array($databaseType, ['char', 'varchar', 'bpchar', 'text', 'citext', 'uuid', 'character varying'], true) => 'string',
                default => null,
            };
            if ($type === null) {
                continue;
            }
            $label = trans_message('ai_assistant_facts.fields.'.$field);
            if ($entityType === 'machinery_asset' && $field === 'current_project_id') {
                $label = trans_message('ai_custom_reports.current_project');
            }
            $fields[$field] = ['type' => $type, 'label' => str_starts_with($label, 'ai_assistant_facts.') ? ($labels[$field] ?? $field) : $label];
            $cast = $model->getCasts()[$field] ?? null;
            $values = $entityType === 'project' && $field === 'status' ? ['draft', 'active', 'completed', 'paused', 'cancelled'] : [];
            if ($entityType === 'machinery_asset' && $field === 'status') {
                $values = ['available', 'assigned', 'in_operation', 'maintenance', 'unavailable', 'archived', 'under_maintenance', 'out_of_service'];
            }
            if (is_string($cast) && enum_exists($cast) && is_subclass_of($cast, \BackedEnum::class)) {
                $values = array_column($cast::cases(), 'value');
            }
            if ($values !== []) {
                $fields[$field]['choices'] = array_map(static fn (string|int $value): array => ['value' => $value,
                    'label' => \App\BusinessModules\Features\AIAssistant\Services\AssistantStructuredFactLabels::display($entityType, $field, (string) $value)], $values);
            }
        }
        if ($members !== null) {
            $fields = array_replace($fields, $members->fields());
        }
        if ($entityType === 'schedule_task') {
            $schedules = $this->policy->entityQuery($actor, $organizationId, 'schedule');
            if ($schedules === null) {
                throw new AccessDeniedHttpException;
            }
            $fields['project_id'] = ['type' => 'number', 'label' => trans_message('ai_assistant_facts.fields.project_id')];
            $fields['is_overdue'] = ['type' => 'boolean', 'label' => trans_message('ai_assistant_facts.fields.is_overdue')];
            if (isset($fields['assigned_user_id'])) {
                $fields['assigned_user_id']['label'] = trans_message('ai_custom_reports.assignee_id');
                $fields['assigned_user_name'] = ['type' => 'string', 'label' => trans_message('ai_assistant_facts.fields.assigned_user_id')];
            }
            $query = (new AssistantScheduleTaskReportProjection)->apply($query, $schedules, $organizationId);
        }
        if ($entityType === 'contract') {
            $fields['counterparty_name'] = ['type' => 'string', 'label' => trans_message('ai_custom_reports.counterparty_name')];
            try {
                $customerProjects = $this->resolve($actor, $organizationId, 'projects', 'project', null)['query'];
            } catch (AccessDeniedHttpException|ValidationException) {
                $customerProjects = null;
            }
            $query = (new AssistantContractReportProjection)->apply($query,
                $this->policy->entityQuery($actor, $organizationId, 'contractor'),
                $this->policy->entityQuery($actor, $organizationId, 'supplier'), $customerProjects, $organizationId);
        }
        foreach ($fields as $field => $metadata) {
            $fields[$field]['aggregations'] = AssistantCustomReportMeasure::aggregations($field, $metadata['type'], $fields);
            $fields[$field]['dimensions'] = $metadata['type'] === 'number' ? AssistantCustomReportMeasure::dimensions($field, $fields) : [];
        }

        return ['query' => $query, 'fields' => $fields, 'domain' => $policyDomain, 'projection' => $projection];
    }

    public function assertFields(array $fields, array $available): void
    {
        if ($fields === [] || array_diff($fields, array_keys($available)) !== []) {
            throw ValidationException::withMessages(['fields' => trans_message('ai_custom_reports.unsupported_fields')]);
        }
    }

    public function related(User $actor, int $organizationId, string $entityType, string $labelField, array $ids): ?array
    {
        foreach ($this->catalog->all() as $definition) {
            if (! in_array($entityType, $definition->entityTypes, true) || ! in_array($labelField, $definition->fields, true)) {
                continue;
            }
            try {
                $source = $this->resolve($actor, $organizationId, $definition->domain, $entityType, null);
                $key = $source['query']->getModel()->getKeyName();
                if (! isset($source['fields'][$key], $source['fields'][$labelField])) {
                    return null;
                }
                $table = $source['query']->getModel()->getTable();
                $rows = $source['query']->whereKey($ids)->select([$table.'.'.$key, $table.'.'.$labelField])->limit(1000)->get();

                return ['labels' => $rows->pluck($labelField, $key)->all(), 'domain' => $source['domain'],
                    'fields' => [$key, $labelField], 'scope' => ['domain' => $definition->domain, 'entity_type' => $entityType, 'project_id' => null]];
            } catch (AccessDeniedHttpException|ValidationException) {
                return null;
            }
        }

        return null;
    }
}
