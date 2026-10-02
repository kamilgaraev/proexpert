<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final readonly class AssistantMaterialStockReader
{
    private const BALANCE_FIELDS = ['warehouse_id', 'material_id', 'available_quantity', 'reserved_quantity'];

    public function __construct(private AssistantDataAccessPolicy $access) {}

    public function read(User $actor, int $organizationId, array $filters): array
    {
        $filters = $this->filters($filters);
        $execution = app()->bound(AssistantRequestExecutionContext::class) ? app(AssistantRequestExecutionContext::class) : null;
        $operation = fn (): array => $this->access->withCurrentChecks($actor, $organizationId,
            fn (): array => $this->readCurrent($actor, $organizationId, $filters), true,
            $execution === null ? null : fn () => $execution->remainingMilliseconds());
        if ($execution === null) {
            return $operation();
        }

        return $execution->withOperationBudget($operation, 12_000);
    }

    public function verifiedAnswer(array $result, User $actor, int $organizationId): ?array
    {
        $receipt = $result['stock_evidence'] ?? null;
        if (! is_array($receipt) || ($receipt['scope'] ?? null) !== 'warehouse_balance_sum'
            || ($receipt['organization_id'] ?? null) !== $organizationId || ($receipt['actor_id'] ?? null) !== (int) $actor->id
            || ! is_array($receipt['filters'] ?? null) || ! is_array($receipt['rows'] ?? null)
            || ! is_string($receipt['version'] ?? null) || $receipt['version'] !== $this->version($receipt)) {
            return null;
        }
        $current = $this->read($actor, $organizationId, $receipt['filters']);
        if (! isset($current['stock_evidence']) || ! hash_equals($receipt['version'], $current['stock_evidence']['version'])) {
            return null;
        }

        return ['text' => $current['server_formatted_answer'], 'validation_status' => 'verified',
            'source_refs' => $current['source_refs'], 'quantity_scope' => $current['quantity_scope'], 'replaced' => true, 'needs_clarification' => false];
    }

    private function readCurrent(User $actor, int $organizationId, array $filters): array
    {
        if (! $this->access->canReadDomain($actor, $organizationId, 'assistant')) {
            return ['status' => 'unavailable', 'stock' => [], 'source_refs' => [], 'validation_status' => 'partial',
                'server_formatted_answer' => trans_message('ai_assistant.material_stock_unavailable')];
        }
        $balances = $this->access->entityQuery($actor, $organizationId, 'warehouse_balance');
        $warehouses = $this->access->entityQuery($actor, $organizationId, 'warehouse');
        $materials = $this->access->entityQuery($actor, $organizationId, 'material');
        $units = $this->access->entityQuery($actor, $organizationId, 'measurement_unit');
        if ($balances === null || $warehouses === null || $materials === null || $units === null) {
            return ['status' => 'unavailable', 'stock' => [], 'source_refs' => [], 'validation_status' => 'partial',
                'server_formatted_answer' => trans_message('ai_assistant.material_stock_unavailable')];
        }
        foreach (['warehouse_id' => ['warehouse', $warehouses], 'project_id' => ['project', null]] as $filter => [$type, $query]) {
            if ($filters[$filter] !== null) {
                $query ??= $this->access->entityQuery($actor, $organizationId, $type);
                if ($query === null || ! (clone $query)->whereKey($filters[$filter])->exists()) {
                    throw new AccessDeniedHttpException;
                }
            }
        }
        if ($filters['material_ids'] !== null && (clone $materials)->whereKey($filters['material_ids'])->count() !== count($filters['material_ids'])) {
            throw new AccessDeniedHttpException;
        }
        $stock = WarehouseBalance::query()->where('warehouse_balances.organization_id', $organizationId)
            ->whereIn('warehouse_balances.id', $balances->select('warehouse_balances.id'))
            ->whereIn('warehouse_balances.warehouse_id', $warehouses->select('organization_warehouses.id'))
            ->whereIn('warehouse_balances.material_id', $materials->select('materials.id'))
            ->where(function (Builder $query): void {
                $query->where('warehouse_balances.available_quantity', '>', 0)->orWhere('warehouse_balances.reserved_quantity', '>', 0);
            });
        if ($filters['warehouse_id'] !== null) {
            $stock->where('warehouse_balances.warehouse_id', $filters['warehouse_id']);
        }
        if ($filters['material_ids'] !== null) {
            $stock->whereIn('warehouse_balances.material_id', $filters['material_ids']);
        }
        if ($filters['query'] !== null) {
            $search = '%'.mb_strtolower($filters['query']).'%';
            $stock->whereHas('material', static function (Builder $query) use ($search): void {
                $query->where(static fn (Builder $material): Builder => $material->whereRaw('LOWER(name) LIKE ?', [$search])->orWhereRaw('LOWER(code) LIKE ?', [$search]));
            });
        }
        if ($filters['project_id'] !== null) {
            $stock->whereExists(static function ($query) use ($filters): void {
                $query->selectRaw('1')->from('warehouse_project_allocations as allocations')
                    ->whereColumn('allocations.organization_id', 'warehouse_balances.organization_id')
                    ->whereColumn('allocations.warehouse_id', 'warehouse_balances.warehouse_id')
                    ->whereColumn('allocations.material_id', 'warehouse_balances.material_id')
                    ->where('allocations.project_id', $filters['project_id']);
            });
        }
        if ((clone $stock)->whereHas('material', static fn (Builder $query): Builder => $query->whereNotNull('measurement_unit_id')
            ->whereNotIn('measurement_unit_id', (clone $units)->select('measurement_units.id')))->exists()) {
            return ['status' => 'unavailable', 'stock' => [], 'source_refs' => [], 'validation_status' => 'partial',
                'server_formatted_answer' => trans_message('ai_assistant.material_stock_unavailable')];
        }
        $query = DB::query()->fromSub($stock->select('warehouse_balances.*')->toBase(), 'stock')
            ->join('materials', 'materials.id', '=', 'stock.material_id')
            ->leftJoin('measurement_units as units', 'units.id', '=', 'materials.measurement_unit_id')
            ->where(static fn ($query) => $query->whereNull('materials.measurement_unit_id')
                ->orWhereIn('materials.measurement_unit_id', $units->select('measurement_units.id')))
            ->select(['stock.material_id', 'materials.name', 'materials.code', 'materials.measurement_unit_id', 'materials.updated_at as material_updated_at',
                'units.short_name as unit_short_name', 'units.name as unit_name', 'units.updated_at as unit_updated_at'])
            ->selectRaw('SUM(stock.available_quantity)::text AS available_quantity, SUM(stock.reserved_quantity)::text AS reserved_quantity')
            ->selectRaw("jsonb_agg(jsonb_build_object('id', stock.id, 'warehouse_id', stock.warehouse_id, 'material_id', stock.material_id, 'available_quantity', stock.available_quantity::text, 'reserved_quantity', stock.reserved_quantity::text) ORDER BY stock.id) AS contributions")
            ->groupBy('stock.material_id', 'materials.name', 'materials.code', 'materials.measurement_unit_id', 'materials.updated_at', 'units.short_name', 'units.name', 'units.updated_at')
            ->orderBy('materials.name')->orderBy('stock.material_id');
        $fetchedAt = now()->toISOString();
        $rows = [];
        $summaries = [];
        $references = [];
        foreach ($query->get() as $record) {
            $contributions = json_decode($record->contributions, true, 512, JSON_THROW_ON_ERROR);
            $row = ['material_id' => (int) $record->material_id, 'material_name' => $record->name, 'material_code' => $record->code,
                'measurement_unit_id' => $record->measurement_unit_id === null ? null : (int) $record->measurement_unit_id,
                'unit_short_name' => $record->unit_short_name, 'unit_name' => $record->unit_name,
                'available_quantity' => $record->available_quantity, 'reserved_quantity' => $record->reserved_quantity,
                'contribution_count' => count($contributions)];
            $summaries[] = $row;
            $row['contributions'] = $contributions;
            $row['material_version'] = $record->material_updated_at === null
                ? AssistantSourceReferenceIdentity::key(['name' => $record->name, 'code' => $record->code, 'measurement_unit_id' => $row['measurement_unit_id']])
                : (string) $record->material_updated_at;
            $row['unit_version'] = $row['measurement_unit_id'] === null ? null : ($record->unit_updated_at === null
                ? AssistantSourceReferenceIdentity::key(['name' => $record->unit_name, 'short_name' => $record->unit_short_name])
                : (string) $record->unit_updated_at);
            $rows[] = $row;
            foreach ($contributions as $contribution) {
                $fields = array_intersect_key($contribution, array_flip(self::BALANCE_FIELDS));
                $references[] = $this->reference('warehouse_balance', $contribution['id'], $organizationId, self::BALANCE_FIELDS,
                    ['warehouse.view'], 'warehouse', AssistantSourceReferenceIdentity::key($fields), $fetchedAt);
            }
            $references[] = $this->reference('material', $row['material_id'], $organizationId, ['name', 'code', 'measurement_unit_id'],
                ['materials.view'], 'materials', $row['material_version'], $fetchedAt);
            if ($row['measurement_unit_id'] !== null) {
                $references[] = $this->reference('measurement_unit', $row['measurement_unit_id'], $organizationId, ['name', 'short_name'],
                    ['measurement_units.view'], 'measurement_units', $row['unit_version'] ?? '', $fetchedAt);
            }
        }
        $quantityScope = ['kind' => $filters['project_id'] === null ? 'warehouse_balance' : 'warehouse_positions_with_project_allocation',
            'project_id' => $filters['project_id'], 'project_allocation_quantity_calculated' => false,
            'on_site_quantity_calculated' => false, 'free_for_allocation_calculated' => false];
        $receipt = ['scope' => 'warehouse_balance_sum', 'quantity_scope' => $quantityScope, 'organization_id' => $organizationId, 'actor_id' => (int) $actor->id,
            'filters' => $filters, 'rows' => $rows, 'fetched_at' => $fetchedAt, 'validation_status' => 'verified'];
        $receipt['version'] = $this->version($receipt);

        return ['status' => $rows === [] ? 'empty' : 'success', 'stock' => $summaries, 'stock_evidence' => $receipt, 'quantity_scope' => $quantityScope,
            'source_refs' => $references, 'server_formatted_answer' => $this->format($summaries, $quantityScope), 'validation_status' => 'verified'];
    }

    private function filters(array $filters): array
    {
        Validator::make($filters, ['query' => ['nullable', 'string', 'max:200'], 'material_ids' => ['nullable', 'array', 'min:1', 'max:100'],
            'material_ids.*' => ['integer', 'min:1', 'distinct'], 'project_id' => ['nullable', 'integer', 'min:1'],
            'warehouse_id' => ['nullable', 'integer', 'min:1']])->validate();
        if (array_diff(array_keys($filters), ['query', 'material_ids', 'project_id', 'warehouse_id']) !== []) {
            throw new \InvalidArgumentException('unsupported_material_stock_argument');
        }
        return ['query' => isset($filters['query']) && trim((string) $filters['query']) !== '' ? trim((string) $filters['query']) : null,
            'material_ids' => isset($filters['material_ids']) ? array_values(array_unique(array_map('intval', $filters['material_ids']))) : null,
            'project_id' => isset($filters['project_id']) ? (int) $filters['project_id'] : null,
            'warehouse_id' => isset($filters['warehouse_id']) ? (int) $filters['warehouse_id'] : null];
    }

    private function version(array $receipt): string
    {
        return AssistantSourceReferenceIdentity::key(array_intersect_key($receipt, array_flip(['scope', 'quantity_scope', 'organization_id', 'actor_id', 'filters', 'rows'])));
    }

    private function reference(string $type, int $id, int $organizationId, array $fields, array $permissions, string $domain, string $version, string $fetchedAt): array
    {
        return ['entity_type' => $type, 'entity_id' => $id, 'organization_id' => $organizationId, 'content_scope' => 'structured',
            'checked_fields' => $fields, 'required_permissions' => $permissions, 'required_domains' => [$domain],
            'source_version' => $version, 'fetched_at' => $fetchedAt, 'navigation' => ['url' => '/warehouse']];
    }

    private function format(array $rows, array $quantityScope): string
    {
        $lines = [trans_message($rows === [] ? 'ai_assistant.material_stock_empty' : 'ai_assistant.material_stock_answer_header')];
        if ($quantityScope['kind'] === 'warehouse_positions_with_project_allocation') {
            $lines[] = trans_message('ai_assistant.material_stock_project_scope_note');
        }
        foreach ($rows as $row) {
            $unit = $row['unit_short_name'] ?: $row['unit_name'] ?: trans_message('ai_assistant.material_stock_unit_unknown');
            $lines[] = trans_message('ai_assistant.material_stock_line', ['name' => $row['material_name'], 'code' => $row['material_code'] ?? '',
                'available' => $row['available_quantity'], 'reserved' => $row['reserved_quantity'], 'unit' => $unit]);
        }
        $lines[] = trans_message('ai_assistant.material_stock_free_allocation_note');

        return implode("\n", $lines);
    }
}
