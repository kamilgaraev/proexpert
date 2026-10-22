<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final readonly class AssistantPurchaseOrderReportProjection
{
    public const HIDDEN_FIELDS = ['_report_order_supplier_kind', '_report_order_supplier_id'];

    public function __construct(private AssistantDataAccessPolicy $policy) {}

    public function apply(Builder $orders, User $actor, int $organizationId): Builder
    {
        $suppliers = $this->policy->entityQuery($actor, $organizationId, 'supplier');

        return $orders->with([
            'supplierParty' => static fn (BelongsTo $query): BelongsTo => $query
                ->where('supplier_parties.organization_id', $organizationId)
                ->select(['supplier_parties.id', 'supplier_parties.organization_id', 'supplier_parties.display_name']),
            'externalSupplierContact' => static fn (BelongsTo $query): BelongsTo => $query
                ->where('external_supplier_contacts.organization_id', $organizationId)
                ->select(['external_supplier_contacts.id', 'external_supplier_contacts.organization_id', 'external_supplier_contacts.name']),
            'supplier' => static function (BelongsTo $query) use ($suppliers, $organizationId): BelongsTo {
                $query->where('suppliers.organization_id', $organizationId)
                    ->select(['suppliers.id', 'suppliers.organization_id', 'suppliers.name']);

                return $suppliers === null ? $query->whereRaw('1 = 0')
                    : $query->whereIn('suppliers.id', (clone $suppliers)->select('suppliers.id'));
            },
        ]);
    }

    public function row(Model $order): array
    {
        if (! $order instanceof PurchaseOrder) {
            throw new LogicException('Unexpected purchase order projection model');
        }
        $snapshot = is_array($order->supplier_snapshot) ? $order->supplier_snapshot : [];
        $name = $this->firstFilledString($snapshot['display_name'] ?? null, $snapshot['name'] ?? null);
        $kind = $name === null ? 'default' : 'snapshot';
        $sourceId = null;
        $unavailable = false;
        if ($name === null) {
            foreach ([
                ['supplierParty', 'supplier_party_id', 'display_name', 'supplier_party'],
                ['externalSupplierContact', 'external_supplier_contact_id', 'name', 'external_supplier_contact'],
                ['supplier', 'supplier_id', 'name', 'supplier'],
            ] as [$relation, $foreignKey, $field, $sourceKind]) {
                if (! $order->relationLoaded($relation)) {
                    throw new LogicException('Purchase order supplier relation was not loaded');
                }
                $related = $order->getRelation($relation);
                if ($related === null) {
                    $unavailable = $unavailable || $order->getAttribute($foreignKey) !== null;

                    continue;
                }
                $name = $this->firstFilledString($related->getAttribute($field));
                if ($name !== null) {
                    $kind = $sourceKind;
                    $sourceId = (int) $related->getKey();
                    break;
                }
            }
            if ($name === null) {
                $kind = $unavailable ? 'unavailable' : 'default';
                $name = $unavailable ? null : trans_message('ai_custom_reports.external_supplier');
            }
        }

        return [...$order->getRawOriginal(), 'supplier_name' => $name,
            '_report_order_supplier_kind' => $kind, '_report_order_supplier_id' => $sourceId];
    }

    public static function links(array $rows): array
    {
        $links = array_map(static fn (array $row): array => ['order_id' => (int) $row['id'],
            'kind' => $row['_report_order_supplier_kind'], 'source_id' => $row['_report_order_supplier_id']], $rows);
        usort($links, static fn (array $left, array $right): int => $left['order_id'] <=> $right['order_id']);

        return $links;
    }

    public function canReadLinks(Builder $orders, mixed $links, array $ids): bool
    {
        if (! is_array($links) || ! array_is_list($links) || count($links) !== count($ids) || count($links) > 1000) {
            return false;
        }
        $seen = [];
        foreach ($links as $link) {
            if (! is_array($link) || count($link) !== 3 || array_diff(['order_id', 'kind', 'source_id'], array_keys($link)) !== []
                || ! is_int($link['order_id']) || $link['order_id'] < 1 || isset($seen[$link['order_id']])
                || ! in_array($link['kind'], ['snapshot', 'supplier_party', 'external_supplier_contact', 'supplier', 'default', 'unavailable'], true)) {
                return false;
            }
            $related = in_array($link['kind'], ['supplier_party', 'external_supplier_contact', 'supplier'], true);
            if ($related ? (! is_int($link['source_id']) || $link['source_id'] < 1) : $link['source_id'] !== null) {
                return false;
            }
            $seen[$link['order_id']] = true;
        }
        $expectedIds = array_map('strval', array_keys($seen));
        $actualIds = array_map('strval', $ids);
        sort($expectedIds, SORT_STRING);
        sort($actualIds, SORT_STRING);
        if ($expectedIds !== $actualIds) {
            return false;
        }
        $current = (clone $orders)->whereKey($ids)->get()->map(fn (Model $order): array => $this->row($order))->all();
        usort($links, static fn (array $left, array $right): int => $left['order_id'] <=> $right['order_id']);

        return self::links($current) === array_map(static fn (array $link): array => [
            'order_id' => $link['order_id'], 'kind' => $link['kind'], 'source_id' => $link['source_id'],
        ], $links);
    }

    private function firstFilledString(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (! is_string($value) && ! is_numeric($value)) {
                continue;
            }
            $normalized = trim((string) $value);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }
}
