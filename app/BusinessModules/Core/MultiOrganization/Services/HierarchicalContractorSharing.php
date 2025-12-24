<?php

namespace App\BusinessModules\Core\MultiOrganization\Services;

use App\BusinessModules\Core\MultiOrganization\Contracts\ContractorSharingInterface;
use App\Enums\ContractorType;
use App\Models\Contractor;
use App\Models\Organization;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class HierarchicalContractorSharing implements ContractorSharingInterface
{
    /**
     * Получить всех доступных подрядчиков для организации
     * 
     * Логика:
     * - Свои подрядчики (manual, invited_organization, holding_member)
     * - Подрядчики головной организации (если есть parent_organization_id)
     * - Дочерние организации как подрядчики (если is_holding)
     */
    public function getAvailableContractors(int $organizationId): Collection
    {
        $org = Organization::find($organizationId);
        
        if (!$org) {
            return Contractor::where('organization_id', $organizationId)->get();
        }

        // Собираем ID организаций, подрядчики которых доступны
        $organizationIds = [$organizationId];

        // Если есть головная организация - добавляем её подрядчиков
        if ($org->parent_organization_id) {
            $organizationIds[] = $org->parent_organization_id;
        }

        // Если это холдинг - добавляем подрядчиков дочерних организаций
        if ($org->is_holding) {
            $childIds = Organization::where('parent_organization_id', $organizationId)
                ->where('is_active', true)
                ->pluck('id')
                ->toArray();
            
            $organizationIds = array_merge($organizationIds, $childIds);
        }

        // Получаем всех подрядчиков из этих организаций
        $contractors = Contractor::whereIn('organization_id', $organizationIds)
            ->with(['sourceOrganization'])
            ->orderBy('name')
            ->get();

        // Добавляем метаданные о источнике подрядчика
        return $contractors->map(function($contractor) use ($org) {
            // Определяем источник подрядчика
            if ($contractor->organization_id === $org->id) {
                $contractor->is_inherited = false;
                $contractor->source = 'own';
                $contractor->source_label = 'Собственный';
            } elseif ($contractor->organization_id === $org->parent_organization_id) {
                $contractor->is_inherited = true;
                $contractor->source = 'holding_head';
                $contractor->source_label = 'Из головной организации';
            } else {
                $contractor->is_inherited = true;
                $contractor->source = 'holding_sibling';
                $contractor->source_label = 'Из дочерней организации';
            }

            // Добавляем информацию о типе
            $contractor->type_label = $contractor->contractor_type?->label() ?? 'Неизвестно';
            $contractor->is_editable = $contractor->isEditable();
            $contractor->is_deletable = $contractor->isDeletable();

            return $contractor;
        });
    }

    public function canUseContractor(int $contractorId, int $organizationId): bool
    {
        $contractor = Contractor::find($contractorId);
        if (!$contractor) {
            return false;
        }

        $org = Organization::find($organizationId);
        if (!$org) {
            return false;
        }

        // Особая логика для подрядчиков типа SELF_EXECUTION:
        // Они доступны только своей организации
        if ($contractor->contractor_type === ContractorType::SELF_EXECUTION) {
            return $contractor->organization_id === $organizationId;
        }

        // Собираем ID всех доступных организаций
        $availableOrgIds = [$organizationId];

        // Добавляем головную организацию
        if ($org->parent_organization_id) {
            $availableOrgIds[] = $org->parent_organization_id;
        }

        // Добавляем дочерние организации (если это холдинг)
        if ($org->is_holding) {
            $childIds = Organization::where('parent_organization_id', $organizationId)
                ->where('is_active', true)
                ->pluck('id')
                ->toArray();
            
            $availableOrgIds = array_merge($availableOrgIds, $childIds);
        }

        // Проверяем, принадлежит ли подрядчик одной из доступных организаций
        return in_array($contractor->organization_id, $availableOrgIds);
    }
}

