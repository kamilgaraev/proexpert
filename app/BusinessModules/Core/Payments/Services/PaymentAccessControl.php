<?php

namespace App\BusinessModules\Core\Payments\Services;

use App\BusinessModules\Core\MultiOrganization\Contracts\OrganizationScopeInterface;
use App\BusinessModules\Core\Payments\Models\Invoice;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Критически важный сервис для project-based изоляции платежей
 * 
 * Обеспечивает безопасный доступ к счетам и транзакциям с учётом:
 * - Прав организации
 * - Участия в проектах
 * - Иерархии холдинга
 * - Взаиморасчётов между контрагентами
 */
class PaymentAccessControl
{
    public function __construct(
        private readonly OrganizationScopeInterface $orgScope,
    ) {}

    /**
     * Проверка доступа к счёту
     * 
     * @param int $organizationId ID текущей организации
     * @param Invoice $invoice Счёт для проверки
     * @return bool Есть ли доступ
     */
    public function canAccessInvoice(int $organizationId, Invoice $invoice): bool
    {
        // 1. Если счёт принадлежит организации - да
        if ($invoice->organization_id === $organizationId) {
            return true;
        }
        
        // 2. Если счёт привязан к проекту
        if ($invoice->project_id) {
            // Проверить участие в проекте через ProjectOrganization
            $isParticipant = DB::table('project_organization')
                ->where('project_id', $invoice->project_id)
                ->where('organization_id', $organizationId)
                ->exists();
            
            if ($isParticipant) {
                return true;
            }
        }
        
        // 3. Проверка холдинга (головная видит дочерние)
        if ($this->isInHoldingHierarchy($organizationId, $invoice->organization_id)) {
            return true;
        }
        
        // 4. Контрагент (взаиморасчёты)
        if ($invoice->counterparty_organization_id === $organizationId) {
            return true;
        }
        
        return false;
    }

    /**
     * Применить фильтр доступа к query builder
     * 
     * КРИТИЧЕСКИ ВАЖНО: ВСЕГДА использовать этот метод при выборке счетов!
     * 
     * @param Builder $query Query builder для Invoice
     * @param int $organizationId ID текущей организации
     * @return Builder Модифицированный query
     */
    public function applyAccessScope(Builder $query, int $organizationId): Builder
    {
        // Получить ID доступных организаций через холдинг
        $orgIds = $this->orgScope->getOrganizationScope($organizationId);
        
        return $query->where(function ($q) use ($organizationId, $orgIds) {
            // 1. Свои счета
            $q->where('organization_id', $organizationId)
              // 2. ИЛИ счета контрагентов (взаиморасчёты)
              ->orWhere('counterparty_organization_id', $organizationId)
              // 3. ИЛИ счета организаций из холдинга
              ->orWhereIn('organization_id', $orgIds)
              // 4. ИЛИ счета по проектам, где участвуем
              ->orWhereHas('project', function ($projectQuery) use ($organizationId) {
                  $projectQuery->whereHas('organizations', function ($orgQuery) use ($organizationId) {
                      $orgQuery->where('organization_id', $organizationId);
                  });
              });
        });
    }

    /**
     * Применить фильтр только для своих счетов организации
     * 
     * @param Builder $query
     * @param int $organizationId
     * @return Builder
     */
    public function applyOwnOnlyScope(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Применить фильтр для счетов по конкретному проекту с проверкой доступа
     * 
     * @param Builder $query
     * @param int $organizationId
     * @param int $projectId
     * @return Builder
     */
    public function applyProjectScope(Builder $query, int $organizationId, int $projectId): Builder
    {
        // Сначала проверяем, есть ли доступ к проекту
        $hasAccess = DB::table('project_organization')
            ->where('project_id', $projectId)
            ->where('organization_id', $organizationId)
            ->exists();

        if (!$hasAccess) {
            // Если нет доступа к проекту - возвращаем пустой результат
            return $query->whereRaw('1 = 0');
        }

        // Если доступ есть - фильтруем по проекту
        return $query->where('project_id', $projectId);
    }

    /**
     * Проверка холдинга - может ли текущая организация видеть целевую
     * 
     * @param int $currentOrgId ID текущей организации
     * @param int $targetOrgId ID целевой организации
     * @return bool
     */
    private function isInHoldingHierarchy(int $currentOrgId, int $targetOrgId): bool
    {
        $currentOrg = Organization::find($currentOrgId);
        
        if (!$currentOrg) {
            return false;
        }
        
        // Если головная организация - видит дочерние
        if ($currentOrg->is_holding) {
            $childIds = Organization::where('parent_organization_id', $currentOrgId)
                ->pluck('id')
                ->toArray();
            
            return in_array($targetOrgId, $childIds);
        }
        
        // Если дочерняя организация - не видит другие дочерние
        // (только через participation в проектах)
        return false;
    }

    /**
     * Проверка прав на создание счёта
     * 
     * @param int $organizationId
     * @param array $data Данные для создания счёта
     * @return bool
     */
    public function canCreateInvoice(int $organizationId, array $data): bool
    {
        // Проверяем, что organization_id совпадает с текущей организацией
        if (isset($data['organization_id']) && $data['organization_id'] !== $organizationId) {
            return false;
        }

        // Если указан project_id - проверяем участие в проекте
        if (isset($data['project_id'])) {
            $hasAccess = DB::table('project_organization')
                ->where('project_id', $data['project_id'])
                ->where('organization_id', $organizationId)
                ->exists();

            if (!$hasAccess) {
                return false;
            }
        }

        return true;
    }

    /**
     * Проверка прав на обновление счёта
     * 
     * @param int $organizationId
     * @param Invoice $invoice
     * @return bool
     */
    public function canUpdateInvoice(int $organizationId, Invoice $invoice): bool
    {
        // Обновлять может только организация-владелец
        return $invoice->organization_id === $organizationId;
    }

    /**
     * Проверка прав на удаление счёта
     * 
     * @param int $organizationId
     * @param Invoice $invoice
     * @return bool
     */
    public function canDeleteInvoice(int $organizationId, Invoice $invoice): bool
    {
        // Удалять может только организация-владелец
        // И только если нет транзакций
        return $invoice->organization_id === $organizationId 
            && $invoice->transactions()->count() === 0;
    }

    /**
     * Получить список организаций, которым можно выставлять счета
     * (для dropdown в UI)
     * 
     * @param int $organizationId
     * @return array [id => name]
     */
    public function getAvailableCounterparties(int $organizationId): array
    {
        $org = Organization::find($organizationId);
        if (!$org) {
            return [];
        }

        $counterparties = [];

        // 1. Организации из проектов, где мы участвуем
        $projectOrgs = DB::table('project_organization as po1')
            ->join('project_organization as po2', 'po1.project_id', '=', 'po2.project_id')
            ->join('organizations', 'organizations.id', '=', 'po2.organization_id')
            ->where('po1.organization_id', $organizationId)
            ->where('po2.organization_id', '!=', $organizationId)
            ->distinct()
            ->select('organizations.id', 'organizations.name')
            ->get();

        foreach ($projectOrgs as $org) {
            $counterparties[$org->id] = $org->name;
        }

        // 2. Если холдинг - дочерние организации
        if ($org->is_holding) {
            $children = Organization::where('parent_organization_id', $organizationId)
                ->where('is_active', true)
                ->pluck('name', 'id')
                ->toArray();
            
            $counterparties = array_merge($counterparties, $children);
        }

        // 3. Если дочерняя - головная организация
        if ($org->parent_organization_id) {
            $parent = Organization::find($org->parent_organization_id);
            if ($parent) {
                $counterparties[$parent->id] = $parent->name . ' (головная)';
            }
        }

        return $counterparties;
    }
}

