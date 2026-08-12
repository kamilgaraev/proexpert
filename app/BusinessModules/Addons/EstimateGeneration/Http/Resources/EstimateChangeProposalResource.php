<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class EstimateChangeProposalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $value = is_array($this->resource) ? $this->resource : [];
        $result = array_intersect_key($value, array_flip([
            'id', 'intent', 'command_excerpt', 'affected_payload', 'assumptions', 'questions', 'cost_delta_known', 'cost_delta',
            'status', 'status_version', 'created_at', 'expires_at', 'applied_at', 'cancelled_at', 'updated_at',
        ]));
        $result['before_payload'] = $this->businessPayload($value['before_payload'] ?? []);
        $result['after_payload'] = $this->businessPayload($value['after_payload'] ?? []);
        $result['evidence'] = array_slice(array_values(array_filter(array_map($this->evidence(...), is_array($value['evidence'] ?? null) ? $value['evidence'] : []))), 0, 100);

        return $result;
    }

    /** @return array<string, mixed> */
    private function businessPayload(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }
        $allowed = ['label', 'field', 'value', 'quantity', 'unit', 'area', 'technology', 'system', 'option', 'section', 'row', 'total', 'price', 'name', 'reason'];

        return array_intersect_key($payload, array_flip($allowed));
    }

    /** @return array<string, int|string|array<string, int|float>|null>|null */
    private function evidence(mixed $evidence): ?array
    {
        if (! is_array($evidence) || ! is_int($evidence['artifact_id'] ?? null)) {
            return null;
        }

        return array_intersect_key($evidence, array_flip(['artifact_id', 'page', 'sheet', 'region', 'native_reference']));
    }
}
