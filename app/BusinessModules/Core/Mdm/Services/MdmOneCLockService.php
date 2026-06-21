<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Services;

use App\Models\OneCExchangeConflict;
use App\Models\OneCExchangeMapping;
use App\Models\OneCExchangeOperation;
use Illuminate\Support\Facades\Schema;

use function trans_message;

class MdmOneCLockService
{
    private const ACTIVE_OPERATION_STATUSES = ['pending', 'queued', 'processing', 'retry_scheduled', 'requires_mapping'];

    private const OPEN_CONFLICT_STATUSES = ['open', 'assigned', 'postponed', 'in_review'];

    public function __construct(
        private readonly MdmEntityGovernanceRegistry $governanceRegistry
    ) {}

    public function summarize(int $organizationId, string $entityType, ?int $entityId, array $diff): array
    {
        $policy = $this->governanceRegistry->get($entityType);
        $scope = $policy['one_c_scope'] ?? null;
        $touchesOneC = $this->touchesOneCFields($entityType, $diff);

        if ($entityId === null || $scope === null || ! $touchesOneC || ! $this->tablesAvailable()) {
            return [
                'scope' => $scope,
                'requires_review' => false,
                'has_mapping' => false,
                'active_operation' => null,
                'open_conflict' => null,
                'blockers' => [],
                'warnings' => [],
            ];
        }

        $mapping = OneCExchangeMapping::query()
            ->where('organization_id', $organizationId)
            ->where('scope', $scope)
            ->where('local_id', $entityId)
            ->whereIn('local_type', [$entityType, $this->modelLocalType($entityType)])
            ->whereNull('archived_at')
            ->orderByDesc('updated_at')
            ->first();

        $operation = OneCExchangeOperation::query()
            ->where('organization_id', $organizationId)
            ->where('scope', $scope)
            ->where('entity_id', $entityId)
            ->whereIn('entity_type', [$entityType, $this->modelLocalType($entityType)])
            ->whereIn('status', self::ACTIVE_OPERATION_STATUSES)
            ->orderByDesc('updated_at')
            ->first();

        $conflict = OneCExchangeConflict::query()
            ->where('organization_id', $organizationId)
            ->where('scope', $scope)
            ->where('entity_id', $entityId)
            ->whereIn('entity_type', [$entityType, $this->modelLocalType($entityType)])
            ->whereIn('status', self::OPEN_CONFLICT_STATUSES)
            ->orderByDesc('detected_at')
            ->first();

        $blockers = [];

        if ($conflict !== null) {
            $blockers[] = [
                'code' => 'one_c_conflict',
                'message' => trans_message('mdm.blockers.one_c_conflict'),
                'conflict_id' => (int) $conflict->id,
            ];
        }

        if ($operation !== null) {
            $blockers[] = [
                'code' => 'one_c_operation_active',
                'message' => trans_message('mdm.blockers.one_c_operation_active'),
                'operation_id' => (int) $operation->id,
            ];
        }

        $warnings = [];
        if ($mapping !== null && $blockers === []) {
            $warnings[] = [
                'code' => 'one_c_mapping_exists',
                'message' => trans_message('mdm.warnings.one_c_mapping_exists'),
                'mapping_id' => (int) $mapping->id,
            ];
        }

        return [
            'scope' => $scope,
            'requires_review' => $mapping !== null || $operation !== null || $conflict !== null,
            'has_mapping' => $mapping !== null,
            'active_operation' => $operation === null ? null : [
                'id' => (int) $operation->id,
                'status' => $operation->status,
            ],
            'open_conflict' => $conflict === null ? null : [
                'id' => (int) $conflict->id,
                'status' => $conflict->status,
                'severity' => $conflict->severity,
            ],
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
    }

    private function touchesOneCFields(string $entityType, array $diff): bool
    {
        foreach ($diff as $item) {
            $field = $item['field'] ?? null;
            if (is_string($field) && $this->governanceRegistry->isOneCField($entityType, $field)) {
                return true;
            }
        }

        return false;
    }

    private function modelLocalType(string $entityType): string
    {
        return match ($entityType) {
            'contractor' => 'App\\Models\\Contractor',
            'supplier' => 'App\\Models\\Supplier',
            'budget_article' => 'App\\BusinessModules\\Features\\Budgeting\\Models\\BudgetArticle',
            'responsibility_center' => 'App\\BusinessModules\\Features\\Budgeting\\Models\\ResponsibilityCenter',
            'project' => 'App\\Models\\Project',
            'contract' => 'App\\Models\\Contract',
            default => $entityType,
        };
    }

    private function tablesAvailable(): bool
    {
        return Schema::hasTable('one_c_exchange_mappings')
            && Schema::hasTable('one_c_exchange_operations')
            && Schema::hasTable('one_c_exchange_conflicts');
    }
}
