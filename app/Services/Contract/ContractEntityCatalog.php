<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\BusinessModules\Core\MultiOrganization\Contracts\ContractorSharingInterface;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\ContractBuilderException;
use App\Models\{Contract, Estimate, Organization, Project, User};
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

final class ContractEntityCatalog
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly ContractorSharingInterface $contractors,
        private readonly ContractAccessService $contracts,
    ) {}

    public function search(User $actor, int $organizationId, string $type, string $search): array
    {
        if (mb_strlen($search) > 255) {
            $this->invalid();
        }
        $query = $this->query($actor, $organizationId, $type);
        $label = $type === 'contract' ? 'number' : 'name';
        if ($search !== '') {
            $query->where($label, 'ilike', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%');
        }

        return $query->orderBy($label)->orderBy('id')->limit(50)->get(['id', $label])
            ->map(fn ($entity): array => ['id' => (int) $entity->id, 'label' => $this->label($entity->getAttribute($label))])->all();
    }

    public function snapshots(User $actor, int $organizationId, array $definitions, array $values, array $frozen = []): array
    {
        if ((int) $actor->current_organization_id !== $organizationId) {
            throw new AuthorizationException;
        }
        $references = [];
        $visited = 0;
        $collect = function (array $definition, mixed $value, int $depth = 0) use (&$collect, &$references, &$visited): void {
            if (++$visited > 100000 || $depth > 4) {
                $this->invalid();
            }
            if ($value === null) {
                return;
            }
            if (($definition['type'] ?? null) === 'entity') {
                if (!is_array($value) || ($value['type'] ?? null) !== $definition['entity_type']
                    || !is_int($value['id'] ?? null) || $value['id'] < 1) {
                    $this->invalid();
                }
                $references[$value['type']][$value['id']] = true;
            } elseif (($definition['type'] ?? null) === 'table' && is_array($value)) {
                foreach ($value as $row) {
                    foreach ($definition['columns'] as $column) {
                        $collect($column['definition'], $row['values'][$column['id']] ?? null, $depth + 1);
                    }
                }
            }
        };
        foreach ($definitions as $id => $definition) {
            $collect($definition, $values[$id] ?? null);
        }
        $snapshots = [];
        foreach ($references as $type => $ids) {
            $missing = [];
            foreach (array_keys($ids) as $id) {
                $key = $type.':'.$id;
                if (isset($frozen[$key])) {
                    $snapshots[$key] = $frozen[$key];
                } else {
                    $missing[] = $id;
                }
            }
            if ($missing === []) {
                continue;
            }
            $label = $type === 'contract' ? 'number' : 'name';
            foreach (array_chunk($missing, 1000) as $chunk) {
                $entities = $this->query($actor, $organizationId, $type)->whereKey($chunk)->get(['id', $label]);
                if ($entities->count() !== count($chunk)) {
                    $this->invalid();
                }
                foreach ($entities as $entity) {
                    $snapshots[$type.':'.$entity->id] = ['label' => $this->label($entity->getAttribute($label))];
                }
            }
        }

        return $snapshots;
    }

    public function sourceValues(User $actor, int $organizationId, array $definitions, array $input, ?array $frozenValues = null, array $context = []): array
    {
        if ((int) $actor->current_organization_id !== $organizationId) {
            throw new AuthorizationException;
        }
        (new ContractFormulaEngine)->validate($definitions);
        $values = [];
        $pending = [];
        foreach ($definitions as $id => $definition) {
            $source = $definition['source'] ?? [];
            if (($source['kind'] ?? null) === 'contract_context') {
                $values[$id] = $frozenValues !== null && array_key_exists($id, $frozenValues)
                    ? $frozenValues[$id] : ContractContextSourceFields::value($context, $source['field']);
                continue;
            }
            if (($source['kind'] ?? null) !== 'entity_field') {
                continue;
            }
            $reference = $input[$source['variable_id']] ?? null;
            $before = $frozenValues[$source['variable_id']] ?? null;
            if ($reference !== null && (!is_array($reference) || ($reference['type'] ?? null) !== $source['entity_type']
                || !is_int($reference['id'] ?? null) || $reference['id'] < 1)) {
                $this->invalid();
            }
            if ($frozenValues !== null && array_key_exists($id, $frozenValues)
                && (($reference === null && $before === null) || (is_array($reference) && is_array($before)
                    && $reference['type'] === ($before['type'] ?? null) && $reference['id'] === ($before['id'] ?? null)))) {
                $values[$id] = $frozenValues[$id];
            } elseif ($reference === null) {
                $values[$id] = null;
            } else {
                $pending[$reference['type']][$reference['id']][$id] = $source['field'];
            }
        }
        foreach ($pending as $type => $references) {
            $columns = ['id'];
            foreach ($references as $fields) {
                foreach ($fields as $field) {
                    $columns[] = $field;
                    if ($type === 'contract' && $field === 'total_amount') {
                        $columns[] = 'currency';
                    }
                }
            }
            foreach (array_chunk(array_keys($references), 1000) as $chunk) {
                $entities = $this->query($actor, $organizationId, $type)->whereKey($chunk)->get(array_values(array_unique($columns)));
                if ($entities->count() !== count($chunk)) {
                    $this->invalid();
                }
                foreach ($entities as $entity) {
                    foreach ($references[$entity->id] as $id => $field) {
                        $value = $entity->getRawOriginal($field);
                        $valueType = ContractSourceFields::type($type, $field);
                        if ($value !== null) {
                            $value = match ($valueType) {
                                'money' => ['amount' => (string) $value, 'currency' => $entity->getRawOriginal('currency')],
                                'date' => substr((string) $value, 0, 10),
                                default => (string) $value,
                            };
                        }
                        $values[$id] = $value;
                    }
                }
            }
        }

        return $values;
    }

    public static function accessible(array $snapshots): \Closure
    {
        return static fn (string $type, int $id): bool => isset($snapshots[$type.':'.$id]);
    }

    private function query(User $actor, int $organizationId, string $type): Builder
    {
        $permission = match ($type) {
            'organization' => 'contracts.view',
            'project' => 'projects.view',
            'contract' => 'contracts.view',
            'counterparty' => 'contractors.view',
            'estimate' => 'budget-estimates.view',
            default => $this->invalid(),
        };
        if ((int) $actor->current_organization_id !== $organizationId
            || !$this->authorization->can($actor, $permission, ['organization_id' => $organizationId])) {
            throw new AuthorizationException;
        }

        return match ($type) {
            'organization' => Organization::query()->whereKey($organizationId),
            'project' => Project::query()->where(function ($query) use ($organizationId): void {
                $query->where('organization_id', $organizationId)->orWhereIn('id', function ($participants) use ($organizationId): void {
                    $participants->select('project_id')->from('project_organization')
                        ->where('organization_id', $organizationId)->where('is_active', true);
                });
            }),
            'contract' => $this->contracts->applyAccessibleScope(Contract::query(), $organizationId),
            'counterparty' => $this->contractors->availableQuery($organizationId),
            'estimate' => Estimate::query()->where('organization_id', $organizationId),
        };
    }

    private function label(mixed $label): string
    {
        if (!is_string($label) || trim($label) === '' || mb_strlen($label) > 1000) {
            $this->invalid();
        }

        return $label;
    }

    private function invalid(): never
    {
        throw new ContractBuilderException('contracts.variable_value_invalid', 422);
    }
}
