<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Services;

use InvalidArgumentException;

use function trans_message;

class MdmEntityGovernanceRegistry
{
    private const POLICY_VERSION = '2026-06-21-mvp';

    public function all(): array
    {
        return [
            'contractor' => [
                'one_c_scope' => 'counterparties',
                'owner_permission' => 'mdm.owners.assign',
                'direct_fields' => ['contact_person', 'phone', 'email', 'notes'],
                'change_request_fields' => ['name', 'legal_address', 'inn', 'kpp', 'bank_details'],
                'locked_fields' => ['id', 'uuid', 'organization_id', 'source_organization_id', 'contractor_type', 'contractor_invitation_id', 'connected_at', 'last_sync_at', 'sync_settings'],
                'critical_fields' => ['name', 'inn', 'kpp', 'bank_details'],
                'one_c_fields' => ['name', 'legal_address', 'inn', 'kpp', 'bank_details'],
            ],
            'supplier' => [
                'one_c_scope' => 'counterparties',
                'owner_permission' => 'mdm.owners.assign',
                'direct_fields' => ['contact_person', 'phone', 'email', 'description', 'additional_info'],
                'change_request_fields' => ['name', 'code', 'inn', 'ogrn', 'address', 'tax_number', 'is_active'],
                'locked_fields' => ['id', 'uuid', 'organization_id'],
                'critical_fields' => ['name', 'inn', 'ogrn', 'tax_number'],
                'one_c_fields' => ['name', 'code', 'inn', 'ogrn', 'tax_number'],
            ],
            'budget_article' => [
                'one_c_scope' => 'cost_categories',
                'owner_permission' => 'mdm.owners.assign',
                'direct_fields' => [],
                'change_request_fields' => ['code', 'name', 'parent_id', 'budget_kind', 'flow_direction', 'is_leaf', 'is_active', 'cost_category_id'],
                'locked_fields' => ['id', 'uuid', 'organization_id', 'created_by', 'updated_by'],
                'critical_fields' => ['code', 'parent_id', 'budget_kind', 'flow_direction', 'is_leaf', 'is_active', 'cost_category_id'],
                'one_c_fields' => ['code', 'name', 'budget_kind', 'flow_direction'],
            ],
            'responsibility_center' => [
                'one_c_scope' => 'cost_centers',
                'owner_permission' => 'mdm.owners.assign',
                'direct_fields' => [],
                'change_request_fields' => ['code', 'name', 'parent_id', 'center_type', 'owner_user_id', 'approver_user_id', 'linked_entity_type', 'linked_entity_id', 'active_from', 'active_to', 'is_active'],
                'locked_fields' => ['id', 'uuid', 'organization_id', 'created_by', 'updated_by'],
                'critical_fields' => ['code', 'parent_id', 'center_type', 'owner_user_id', 'approver_user_id', 'linked_entity_type', 'linked_entity_id', 'active_from', 'active_to', 'is_active'],
                'one_c_fields' => ['code', 'name', 'center_type', 'linked_entity_type', 'linked_entity_id'],
            ],
            'project' => [
                'one_c_scope' => 'projects',
                'owner_permission' => 'mdm.owners.assign',
                'direct_fields' => ['description'],
                'change_request_fields' => ['external_code', 'cost_category_id', 'use_in_accounting_reports', 'customer_organization', 'customer_representative', 'contract_number', 'contract_date'],
                'locked_fields' => ['id', 'uuid', 'organization_id', 'status', 'is_archived', 'is_head'],
                'critical_fields' => ['external_code', 'cost_category_id', 'use_in_accounting_reports'],
                'one_c_fields' => ['external_code', 'cost_category_id', 'use_in_accounting_reports', 'contract_number', 'contract_date'],
            ],
            'contract' => [
                'one_c_scope' => 'contracts',
                'owner_permission' => 'mdm.owners.assign',
                'direct_fields' => ['notes'],
                'change_request_fields' => ['number', 'date', 'subject', 'payment_terms'],
                'locked_fields' => ['id', 'uuid', 'organization_id', 'project_id', 'contractor_id', 'supplier_id', 'status', 'total_amount', 'base_amount'],
                'critical_fields' => ['number', 'date', 'payment_terms'],
                'one_c_fields' => ['number', 'date', 'subject', 'payment_terms'],
            ],
        ];
    }

    public function get(string $entityType): array
    {
        $policies = $this->all();

        if (! array_key_exists($entityType, $policies)) {
            throw new InvalidArgumentException(trans_message('mdm.errors.entity_not_supported'));
        }

        return array_merge([
            'entity_type' => $entityType,
            'policy_version' => self::POLICY_VERSION,
        ], $policies[$entityType]);
    }

    public function has(string $entityType): bool
    {
        return array_key_exists($entityType, $this->all());
    }

    public function publicPolicy(string $entityType): ?array
    {
        if (! $this->has($entityType)) {
            return null;
        }

        $policy = $this->get($entityType);

        return [
            'policy_version' => $policy['policy_version'],
            'owner_policy' => [
                'default_owner_source' => 'mdm_record',
                'assignable' => true,
                'owner_permission' => $policy['owner_permission'],
            ],
            'field_policy' => [
                'direct_fields' => $policy['direct_fields'],
                'change_request_fields' => $policy['change_request_fields'],
                'locked_fields' => $policy['locked_fields'],
                'critical_fields' => $policy['critical_fields'],
            ],
            'field_labels' => $this->fieldLabels($entityType),
            'one_c_scope' => $policy['one_c_scope'],
        ];
    }

    public function classifyField(string $entityType, string $field): string
    {
        $policy = $this->get($entityType);

        if (in_array($field, $policy['locked_fields'], true)) {
            return 'locked';
        }

        if (in_array($field, $policy['change_request_fields'], true)) {
            return 'change_request';
        }

        if (in_array($field, $policy['direct_fields'], true)) {
            return 'direct';
        }

        return 'unsupported';
    }

    public function isCritical(string $entityType, string $field): bool
    {
        return in_array($field, $this->get($entityType)['critical_fields'], true);
    }

    public function isOneCField(string $entityType, string $field): bool
    {
        return in_array($field, $this->get($entityType)['one_c_fields'], true);
    }

    public function fieldLabel(string $entityType, string $field): string
    {
        $label = trans_message("mdm.fields.{$entityType}.{$field}");

        return $label === "mdm.fields.{$entityType}.{$field}" ? $field : $label;
    }

    private function fieldLabels(string $entityType): array
    {
        $policy = $this->get($entityType);
        $fields = array_values(array_unique(array_merge(
            $policy['direct_fields'],
            $policy['change_request_fields'],
            $policy['locked_fields'],
        )));

        return collect($fields)
            ->mapWithKeys(fn (string $field): array => [$field => $this->fieldLabel($entityType, $field)])
            ->all();
    }
}
