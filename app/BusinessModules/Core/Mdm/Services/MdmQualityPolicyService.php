<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Services;

use App\BusinessModules\Core\Mdm\Models\MdmQualityPolicy;

class MdmQualityPolicyService
{
    public function defaults(): array
    {
        return [
            'contractor' => ['required_fields' => ['name'], 'field_weights' => ['name' => 25, 'inn' => 20, 'kpp' => 10, 'email' => 10, 'normalized_key' => 15], 'min_acceptable_score' => 70],
            'supplier' => ['required_fields' => ['name'], 'field_weights' => ['name' => 25, 'inn' => 20, 'email' => 10, 'normalized_key' => 15], 'min_acceptable_score' => 70],
            'material' => ['required_fields' => ['name', 'measurement_unit_id'], 'field_weights' => ['name' => 25, 'measurement_unit_id' => 25, 'default_price' => 15, 'normalized_key' => 15], 'min_acceptable_score' => 70],
            'measurement_unit' => ['required_fields' => ['name', 'short_name', 'type'], 'field_weights' => ['name' => 20, 'short_name' => 25, 'type' => 20, 'normalized_key' => 15], 'min_acceptable_score' => 70],
            'work_type' => ['required_fields' => ['name', 'measurement_unit_id'], 'field_weights' => ['name' => 25, 'measurement_unit_id' => 25, 'normalized_key' => 15], 'min_acceptable_score' => 70],
            'cost_category' => ['required_fields' => ['name'], 'field_weights' => ['name' => 25, 'normalized_key' => 15], 'min_acceptable_score' => 70],
            'estimate_position' => ['required_fields' => ['name', 'code', 'measurement_unit_id'], 'field_weights' => ['name' => 25, 'code' => 20, 'measurement_unit_id' => 25, 'normalized_key' => 15], 'min_acceptable_score' => 70],
            'estimate_position_category' => ['required_fields' => ['name'], 'field_weights' => ['name' => 25, 'normalized_key' => 15], 'min_acceptable_score' => 70],
        ];
    }

    public function get(?int $organizationId, string $entityType): array
    {
        $policy = $organizationId === null ? null : MdmQualityPolicy::query()
            ->where('organization_id', $organizationId)
            ->where('entity_type', $entityType)
            ->first();

        if ($policy !== null) {
            return $policy->toArray();
        }

        return $this->defaults()[$entityType] ?? [
            'required_fields' => ['name'],
            'field_weights' => ['name' => 25, 'normalized_key' => 15],
            'min_acceptable_score' => 70,
        ];
    }

    public function upsert(int $organizationId, string $entityType, array $data): MdmQualityPolicy
    {
        return MdmQualityPolicy::query()->updateOrCreate(
            ['organization_id' => $organizationId, 'entity_type' => $entityType],
            [
                'required_fields' => array_values($data['required_fields'] ?? []),
                'field_weights' => $data['field_weights'] ?? [],
                'validation_rules' => $data['validation_rules'] ?? null,
                'min_acceptable_score' => (int) ($data['min_acceptable_score'] ?? 70),
            ]
        );
    }
}
