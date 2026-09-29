<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\DomainMetadata;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantStructuredFactFormatter;
use App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceIdentity;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class AssistantSalesParentProjection
{
    public function __construct(private readonly AssistantDataAccessPolicy $policy, private readonly AuthorizationService $authorization) {}

    public function read(User $actor, int $organizationId, Model $currentParent, string $parentType, string $projection, int $offset = 0, int $limit = 20): array
    {
        if ($offset < 0 || $offset > 1000000 || $limit < 1 || $limit > 20) {
            throw ValidationException::withMessages(['pagination' => ['invalid_projection_pagination']]);
        }
        [$parent, $definition] = $this->parent($actor, $organizationId, $currentParent, $parentType, $projection);
        $fields = $this->allowedFields($actor, $organizationId, $definition);
        $query = $this->query($actor, $organizationId, $parent, $definition);
        $selected = array_values(array_unique([...$fields, ...array_keys($definition['matches'] ?? [])]));
        $records = $query->select($selected)->orderBy($definition['ordinal_column'])->offset($offset)->limit($limit + 1)->get();
        $hasMore = $records->count() > $limit;
        $rows = [];
        $fetchedAt = now()->toISOString();
        foreach ($records->take($limit) as $record) {
            $values = self::values((array) $record, $fields);
            $key = [$definition['parent_column'] => $parent->getRawOriginal($definition['parent_key'] ?? $parent->getKeyName()), $definition['ordinal_column'] => $record->{$definition['ordinal_column']}];
            $reference = $this->reference($organizationId, $parentType, $parent, $projection, $definition, $key, $values, $fetchedAt);
            $row = ['entity_type' => $parentType, 'entity_id' => $parent->getKey(), 'composite_key' => $key, 'fields' => $values,
                'source_ref' => $reference, 'source_version' => $reference['source_version']];
            $row['version'] = self::hash($row);
            $rows[] = $row;
        }
        return ['rows' => $rows, 'source_refs' => array_column($rows, 'source_ref'), 'fetched_at' => $fetchedAt,
            'pagination' => ['offset' => $offset, 'limit' => $limit, 'has_more' => $hasMore, 'next_offset' => $hasMore ? $offset + $limit : null]]
            + AssistantStructuredFactFormatter::payload($rows, $fetchedAt);
    }

    public function canReadReference(User $actor, int $organizationId, array $reference): bool
    {
        try {
            $scope = $this->referenceScope($actor, $organizationId, $reference);
            if ($scope === null) { return false; }
            [, , $key, , $parent, $definition] = $scope;
            return $this->query($actor, $organizationId, $parent, $definition)
                ->where($definition['ordinal_column'], $key[$definition['ordinal_column']])->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    public function fresh(User $actor, int $organizationId, array $reference): bool
    {
        try {
            $fetchedAt = $reference['fetched_at'] ?? null;
            if (! is_string($fetchedAt)) { return false; }
            $time = Carbon::parse($fetchedAt);
            if ($time->isFuture() || $time->lt(now()->subMinutes(5))) { return false; }
            $scope = $this->referenceScope($actor, $organizationId, $reference);
            if ($scope === null) { return false; }
            [$type, $projection, $key, $fields, $parent, $definition] = $scope;
            $row = $this->query($actor, $organizationId, $parent, $definition)
                ->where($definition['ordinal_column'], $key[$definition['ordinal_column']])->first($fields);
            if ($row === null) { return false; }
            $values = self::values((array) $row, $fields);
            $expected = $this->reference($organizationId, $type, $parent, $projection, $definition, $key, $values, $fetchedAt);
            return hash_equals(AssistantSourceReferenceIdentity::key($expected), AssistantSourceReferenceIdentity::key($reference));
        } catch (\Throwable) {
            return false;
        }
    }

    private function referenceScope(User $actor, int $organizationId, array $reference): ?array
    {
        $type = $reference['entity_type'] ?? null;
        $projection = $reference['projection_name'] ?? null;
        $key = $reference['composite_key'] ?? null;
        $fields = $reference['checked_fields'] ?? null;
        $id = $reference['entity_id'] ?? null;
        if ($organizationId < 1 || ($reference['organization_id'] ?? null) !== $organizationId
            || ! is_string($type) || ! is_string($projection) || ! is_string($id)
            || ! preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/Di', $id)
            || ($reference['content_scope'] ?? null) !== 'structured' || ($reference['source_type'] ?? null) !== 'procurement_business'
            || ! is_array($key) || ! is_array($fields) || ! array_is_list($fields) || $fields === []) { return null; }
        $definition = AssistantSalesBusinessMetadata::parentProjectionDefinitions()[$type][$projection] ?? null;
        if ($definition === null || ! self::validCompositeKey($key, $definition)) { return null; }
        foreach ($fields as $field) { if (! is_string($field)) { return null; } }
        if (count(array_unique($fields)) !== count($fields) || array_diff($fields, $this->allowedFields($actor, $organizationId, $definition)) !== []) { return null; }
        $required = AssistantSalesBusinessMetadata::entityPermissions()[$type];
        foreach ($fields as $field) { $required = [...$required, ...($definition['field_permissions'][$field] ?? [])]; }
        if (! self::sameStringSet($reference['required_permissions'] ?? null, array_values(array_unique($required)))
            || ! self::sameStringSet($reference['required_domains'] ?? null, ['procurement_business'])
            || ! $this->policy->canReadDomain($actor, $organizationId, 'procurement_business')) { return null; }
        foreach ($required as $permission) {
            if (! $this->authorization->canCurrent($actor, $permission, ['organization_id' => $organizationId])) { return null; }
        }
        $parents = $this->policy->entityQuery($actor, $organizationId, $type);
        $parent = $parents?->select([$parents->getModel()->getQualifiedKeyName()])->whereKey($id)->first();
        if ($parent === null) { return null; }
        [$parent, $definition] = $this->parent($actor, $organizationId, $parent, $type, $projection);
        if ((string) $key[$definition['parent_column']] !== (string) $parent->getRawOriginal($definition['parent_key'] ?? $parent->getKeyName())) { return null; }
        $canonicalKey = [$definition['parent_column'] => $key[$definition['parent_column']], $definition['ordinal_column'] => $key[$definition['ordinal_column']]];
        return [$type, $projection, $canonicalKey, $fields, $parent, $definition];
    }

    private static function validCompositeKey(array $key, array $definition): bool
    {
        $expected = [$definition['parent_column'], $definition['ordinal_column']];
        if (count($key) !== 2 || array_diff(array_keys($key), $expected) !== [] || array_diff($expected, array_keys($key)) !== []) { return false; }
        $parentId = $key[$definition['parent_column']];
        $ordinal = $key[$definition['ordinal_column']];
        return is_string($parentId) && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/Di', $parentId) === 1
            && (is_int($ordinal) || is_string($ordinal)) && preg_match('/^[1-9][0-9]{0,9}$/D', (string) $ordinal) === 1;
    }

    private static function sameStringSet(mixed $given, array $expected): bool
    {
        if (! is_array($given) || ! array_is_list($given)) { return false; }
        foreach ($given as $value) { if (! is_string($value)) { return false; } }
        if (count(array_unique($given)) !== count($given)) { return false; }
        sort($given, SORT_STRING);
        sort($expected, SORT_STRING);
        return $given === $expected;
    }

    private function parent(User $actor, int $organizationId, Model $currentParent, string $type, string $projection): array
    {
        $record = AssistantSalesBusinessMetadata::recordDefinitions()[$type] ?? null;
        $definition = AssistantSalesBusinessMetadata::parentProjectionDefinitions()[$type][$projection] ?? null;
        if ($record === null || $definition === null || ! $currentParent instanceof $record['model']) {
            throw ValidationException::withMessages(['projection' => ['unsupported_parent_projection']]);
        }
        $parents = $this->policy->entityQuery($actor, $organizationId, $type);
        if ($parents === null) { throw new AccessDeniedHttpException(); }
        $table = $parents->getModel()->getTable();
        $columns = array_values(array_unique([$parents->getModel()->getKeyName(), 'project_id', $definition['parent_key'] ?? $parents->getModel()->getKeyName(), ...array_values($definition['matches'] ?? []), ...$record['version_columns']]));
        $parent = $parents->select(array_map(static fn (string $column): string => $table.'.'.$column, $columns))->whereKey($currentParent->getKey())->first();
        if ($parent === null) { throw new AccessDeniedHttpException(); }
        return [$parent, $definition];
    }

    private function allowedFields(User $actor, int $organizationId, array $definition): array
    {
        return array_values(array_filter($definition['fields'], function (string $field) use ($actor, $organizationId, $definition): bool {
            foreach ($definition['field_permissions'][$field] ?? [] as $permission) {
                if (! $this->authorization->canCurrent($actor, $permission, ['organization_id' => $organizationId])) { return false; }
            }
            return true;
        }));
    }

    private function query(User $actor, int $organizationId, Model $parent, array $definition): Builder
    {
        $table = $definition['table'];
        $query = DB::table($table)->where($table.'.'.$definition['parent_column'], $parent->getRawOriginal($definition['parent_key'] ?? $parent->getKeyName()));
        foreach ($definition['matches'] ?? [] as $column => $parentColumn) {
            $query->where($table.'.'.$column, $parent->getRawOriginal($parentColumn));
        }
        foreach ($definition['parents'] ?? [] as $column => $relation) {
            $parents = $this->policy->entityQuery($actor, $organizationId, $relation['type']);
            if ($parents === null) {
                $relation['nullable'] ? $query->whereNull($table.'.'.$column) : $query->whereRaw('1 = 0');
                continue;
            }
            $parentTable = $parents->getModel()->getTable();
            $parentKey = $relation['key'] ?? $parents->getModel()->getKeyName();
            foreach ($relation['matches'] ?? [] as $parentColumn => $childColumn) {
                $parents->whereColumn($parentTable.'.'.$parentColumn, $table.'.'.$childColumn);
            }
            $parents->select([])->selectRaw('CAST('.$parents->getModel()->getConnection()->getQueryGrammar()->wrap($parentTable.'.'.$parentKey).' AS TEXT)');
            $wrapped = $query->getGrammar()->wrap($table.'.'.$column);
            $query->where(function (Builder $scope) use ($relation, $parents, $wrapped, $table, $column): void {
                if ($relation['nullable']) { $scope->whereNull($table.'.'.$column)->orWhereRaw('CAST('.$wrapped.' AS TEXT) IN ('.$parents->toSql().')', $parents->getBindings()); }
                else { $scope->whereRaw('CAST('.$wrapped.' AS TEXT) IN ('.$parents->toSql().')', $parents->getBindings()); }
            });
        }
        return $query;
    }

    private function reference(int $organizationId, string $type, Model $parent, string $projection, array $definition, array $key, array $values, string $fetchedAt): array
    {
        $required = AssistantSalesBusinessMetadata::entityPermissions()[$type];
        foreach (array_keys($values) as $field) { $required = [...$required, ...($definition['field_permissions'][$field] ?? [])]; }
        $pin = [];
        foreach ($definition['matches'] ?? [] as $column) { $pin[$column] = $parent->getRawOriginal($column); }
        $version = self::hash(['parent' => $parent->getKey(), 'pin' => $pin, 'key' => $key, 'values' => $values]);
        return ['organization_id' => $organizationId, 'source_type' => 'procurement_business', 'entity_type' => $type, 'entity_id' => $parent->getKey(),
            'project_id' => $parent->getAttribute('project_id'), 'navigation' => ['url' => '/procurement'], 'content_scope' => 'structured',
            'projection_name' => $projection, 'composite_key' => $key, 'checked_fields' => array_keys($values),
            'required_permissions' => array_values(array_unique($required)), 'required_domains' => ['procurement_business'],
            'source_version' => $version, 'projection_source_version' => $version, 'fetched_at' => $fetchedAt];
    }

    private static function hash(array $values): string
    {
        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }

    private static function values(array $record, array $fields): array
    {
        $values = [];
        foreach ($fields as $field) {
            if (! array_key_exists($field, $record)) { continue; }
            $value = $record[$field];
            if ($value !== null && ! is_string($value) && ! is_int($value) && ! is_bool($value)) { continue; }
            if (in_array($field, AssistantSalesBusinessMetadata::numericFields(), true) && $value !== null
                && ((! is_string($value) && ! is_int($value)) || preg_match('/^-?\d+(?:\.\d+)?$/D', (string) $value) !== 1)) { continue; }
            $values[$field] = $value;
        }
        return $values;
    }
}
