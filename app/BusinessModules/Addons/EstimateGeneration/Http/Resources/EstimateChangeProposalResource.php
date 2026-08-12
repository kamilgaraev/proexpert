<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RuntimeException;

final class EstimateChangeProposalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $value = is_array($this->resource) ? $this->resource : [];
        $this->assertStatusShape($value);
        $result = array_intersect_key($value, array_flip([
            'id', 'intent', 'interpretation_version', 'command_excerpt', 'affected_payload', 'dependency_keys',
            'assumptions', 'questions', 'cost_state', 'cost_blockers', 'cost_delta_known', 'cost_delta',
            'status', 'status_version', 'created_at', 'expires_at', 'applied_at', 'cancelled_at', 'updated_at',
        ]));
        $result['applied_at'] = $value['applied_at'] ?? null;
        $result['cancelled_at'] = $value['cancelled_at'] ?? null;
        $result['result_summary'] = ($value['status'] ?? null) === 'applied'
            ? [
                'outcome' => 'applied',
                'reanalysis_requested' => (bool) ($value['result']['reanalysis_requested'] ?? false),
            ]
            : null;
        $result['before_payload'] = $this->businessPayload($value['before_payload'] ?? []);
        $result['after_payload'] = $this->businessPayload($value['after_payload'] ?? []);
        $result['evidence'] = array_slice(array_values(array_filter(array_map($this->evidence(...), is_array($value['evidence'] ?? null) ? $value['evidence'] : []))), 0, 100);

        return $result;
    }

    /** @param array<string, mixed> $value */
    private function assertStatusShape(array $value): void
    {
        $status = $value['status'] ?? null;
        $appliedAt = $value['applied_at'] ?? null;
        $cancelledAt = $value['cancelled_at'] ?? null;
        if (! in_array($status, ['proposed', 'applying', 'applied', 'cancelled', 'expired', 'stale', 'failed'], true)
            || ($status === 'applied' && ! is_string($appliedAt))
            || ($status === 'applied' && ! is_array($value['result'] ?? null))
            || ($status !== 'applied' && $appliedAt !== null)
            || ($status === 'cancelled' && ! is_string($cancelledAt))
            || ($status !== 'cancelled' && $cancelledAt !== null)) {
            throw new RuntimeException('estimate_generation.proposal_resource_status_invalid');
        }
    }

    /** @return array<string, mixed> */
    private function businessPayload(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }
        $allowed = [
            'label', 'field', 'value', 'quantity', 'unit', 'area', 'technology', 'system', 'option',
            'section', 'row', 'total', 'price', 'name', 'reason', 'stable_key', 'decision_key',
            'selected_option', 'response',
        ];

        return array_intersect_key($payload, array_flip($allowed));
    }

    /** @return array<string, int|string|array<string, int|float>|null>|null */
    private function evidence(mixed $evidence): ?array
    {
        if (! is_array($evidence) || ! is_int($evidence['artifact_id'] ?? null)) {
            return null;
        }

        return array_intersect_key($evidence, array_flip([
            'artifact_id', 'source_version', 'representation_kind', 'page', 'sheet', 'region', 'native_reference',
        ]));
    }
}
