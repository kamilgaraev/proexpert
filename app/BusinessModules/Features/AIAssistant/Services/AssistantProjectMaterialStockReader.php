<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\BasicWarehouse\Models\ProjectMaterialDelivery;
use App\BusinessModules\Features\BasicWarehouse\Services\ProjectMaterialStockService;
use App\Models\Material;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class AssistantProjectMaterialStockReader
{
    public const TYPE = 'live_project_material_stock_projection';

    public function __construct(private AssistantDataAccessPolicy $access, private ProjectMaterialStockService $native) {}

    public function read(User $actor, int $organizationId, array $filters): array
    {
        return $this->access->withCurrentChecks($actor, $organizationId, function () use ($actor, $organizationId, $filters): array {
            $projectId = $filters['project_id'] ?? null;
            $offset = $filters['offset'] ?? 0;
            $limit = $filters['limit'] ?? 20;
            if (! is_int($projectId) || $projectId < 1 || ! is_int($offset) || $offset < 0 || $offset > 1000000
                || ! is_int($limit) || $limit < 1 || $limit > 20
                || array_diff(array_keys($filters), ['project_id', 'offset', 'limit']) !== []) { throw new \InvalidArgumentException('invalid_project_stock_scope'); }
            if (! $this->access->canReadDomain($actor, $organizationId, 'assistant')
                || ! $this->access->canReadDomain($actor, $organizationId, 'warehouse')
                || ! $this->access->canCurrentPermission($actor, $organizationId, 'warehouse.manage_stock')
                || ! $this->access->canReadEntity($actor, $organizationId, 'project', $projectId)) { throw new AuthorizationException; }
            $deliveries = ProjectMaterialDelivery::query()->where('organization_id', $organizationId)->where('project_id', $projectId)->where('status', 'accepted');
            if (\Illuminate\Support\Facades\DB::query()->fromSub((clone $deliveries)->select('id')->limit(2001), 'stock_delivery_bounds')->count() > 2000) {
                return ['status' => 'unavailable', 'reason' => 'source_read_limit', 'source_refs' => [], 'validation_status' => 'partial'];
            }
            $materialIds = (clone $deliveries)->distinct()->pluck('material_id')->all();
            $materials = Material::query()->where('organization_id', $organizationId)->whereKey($materialIds)->get(['id', 'measurement_unit_id']);
            if ($materials->count() !== count($materialIds)) { throw new AuthorizationException; }
            $unitIds = $materials->pluck('measurement_unit_id')->filter()->unique()->values()->all();
            if (\App\Models\MeasurementUnit::query()->where('organization_id', $organizationId)->whereKey($unitIds)->count() !== count($unitIds)) { throw new AuthorizationException; }
            $native = $this->native->getProjectStock($organizationId, $projectId);
            $rows = [];
            foreach ($native['items'] as $item) {
                if (($item['project']['id'] ?? null) !== $projectId || ! is_int($item['material']['id'] ?? null)) { throw new AuthorizationException; }
                $row = ['material_id' => $item['material']['id'], 'name' => $item['material']['name'],
                    'unit' => $item['material']['measurement_unit']['short_name'] ?? $item['material']['measurement_unit']['name'] ?? null];
                foreach (['accepted_quantity', 'on_project_quantity', 'issued_quantity', 'used_quantity', 'available_quantity'] as $field) {
                    $value = $item[$field] ?? null;
                    if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value) || abs($value) > 1e12) {
                        return ['status' => 'unavailable', 'reason' => 'quantity_unproven', 'source_refs' => [], 'validation_status' => 'partial'];
                    }
                    $row[$field] = number_format((float) $value, 3, '.', '');
                }
                $rows[] = $row;
            }
            usort($rows, static fn (array $left, array $right): int => $left['material_id'] <=> $right['material_id']);
            $filters = ['project_id' => $projectId, 'offset' => $offset, 'limit' => $limit];
            $version = AssistantSourceReferenceIdentity::key(['schema' => 1, 'organization_id' => $organizationId, 'actor_id' => (int) $actor->id, 'filters' => $filters, 'rows' => $rows]);
            $ref = ['source_type' => self::TYPE, 'entity_type' => self::TYPE, 'entity_id' => $projectId, 'project_id' => $projectId,
                'projection_name' => self::TYPE,
                'organization_id' => $organizationId, 'actor_id' => (int) $actor->id, 'stock_filters' => $filters,
                'source_version' => $version, 'fetched_at' => now()->toIso8601String()];
            $page = array_slice($rows, $offset, $limit);
            $lines = [trans_message('ai_assistant.project_material_stock_scope')];
            foreach ($page as $row) {
                $lines[] = trans_message('ai_assistant.project_material_stock_row', ['name' => self::text((string) $row['name']),
                    'unit' => self::text((string) ($row['unit'] ?? trans_message('ai_assistant_facts.unknown'))),
                    'on_project' => $row['on_project_quantity'], 'issued' => $row['issued_quantity'], 'used' => $row['used_quantity'], 'available' => $row['available_quantity']]);
            }
            if ($rows === []) { $lines[] = trans_message('ai_assistant.project_material_stock_empty'); }
            $more = $offset + count($page) < count($rows);
            if ($more) { $lines[] = trans_message('ai_assistant.project_material_stock_more', ['shown' => count($page), 'total' => count($rows)]); }
            $lines[] = trans_message('ai_assistant_operational.stock_open');
            return ['status' => $rows === [] ? 'empty' : 'success', 'stock' => $page, 'total' => count($rows), 'has_more' => $more,
                'next_offset' => $more ? $offset + count($page) : null, 'source_refs' => [$ref], 'validation_status' => 'verified',
                'stock_evidence' => ['scope' => 'native_project_delivery_stock', 'filters' => $filters, 'version' => $version],
                'server_formatted_answer' => implode("\n", $lines)];
        }, true);
    }

    public function matchesReference(User $actor, int $organizationId, array $reference): bool
    {
        if (($reference['entity_type'] ?? null) !== self::TYPE || ($reference['organization_id'] ?? null) !== $organizationId
            || ($reference['actor_id'] ?? null) !== (int) $actor->id || ! is_array($reference['stock_filters'] ?? null)
            || ($reference['stock_filters']['project_id'] ?? null) !== ($reference['entity_id'] ?? null)
            || ($reference['project_id'] ?? null) !== ($reference['entity_id'] ?? null)) { return false; }
        try {
            $current = $this->read($actor, $organizationId, $reference['stock_filters']);
            return ($current['stock_evidence']['version'] ?? null) === ($reference['source_version'] ?? null)
                && is_string($reference['source_version'] ?? null);
        } catch (\Throwable) { return false; }
    }

    private static function text(string $value): string
    {
        $value = mb_substr(trim(preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', ' ', $value) ?? ''), 0, 255);
        $escapes = ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;'];
        foreach (str_split('!"#$%\'()*+,-./:;=?@[\\]^_`{|}~') as $character) { $escapes[$character] = '\\'.$character; }
        return strtr($value, $escapes);
    }

    public function verifiedAnswer(array $result, User $actor, int $organizationId): ?array
    {
        $ref = $result['source_refs'][0] ?? null;
        if (! is_array($ref) || ($ref['entity_type'] ?? null) !== self::TYPE
            || ($ref['organization_id'] ?? null) !== $organizationId || ($ref['actor_id'] ?? null) !== (int) $actor->id
            || ! is_array($ref['stock_filters'] ?? null)) { return null; }
        $current = $this->read($actor, $organizationId, $ref['stock_filters']);
        if (! is_string($ref['source_version'] ?? null) || ($current['stock_evidence']['version'] ?? null) !== $ref['source_version']) { return null; }
        return ['text' => $current['server_formatted_answer'], 'validation_status' => 'verified', 'source_refs' => $current['source_refs'],
            'replaced' => true, 'needs_clarification' => false];
    }
}
