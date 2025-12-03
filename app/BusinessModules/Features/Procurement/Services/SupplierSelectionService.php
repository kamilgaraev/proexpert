<?php

namespace App\BusinessModules\Features\Procurement\Services;

use App\Models\Supplier;
use App\BusinessModules\Features\Procurement\Models\SupplierProposal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Сервис для выбора поставщиков и сравнения КП
 */
class SupplierSelectionService
{
    /**
     * Получить доступных поставщиков для материалов
     */
    public function getAvailableSuppliers(int $organizationId, array $materialIds = []): Collection
    {
        $query = Supplier::where('organization_id', $organizationId)
            ->where('is_active', true);

        // Если указаны материалы, можно добавить фильтрацию по поставщикам,
        // которые поставляют эти материалы (если есть такая связь в будущем)

        return $query->get();
    }

    /**
     * Сравнить коммерческие предложения
     */
    public function compareProposals(Collection $proposals): array
    {
        $comparison = [];

        foreach ($proposals as $proposal) {
            $comparison[] = [
                'id' => $proposal->id,
                'supplier_id' => $proposal->supplier_id,
                'supplier_name' => $proposal->supplier->name ?? 'Неизвестно',
                'proposal_number' => $proposal->proposal_number,
                'total_amount' => $proposal->total_amount,
                'currency' => $proposal->currency,
                'valid_until' => $proposal->valid_until?->toDateString(),
                'is_expired' => $proposal->isExpired(),
                'status' => $proposal->status->value,
            ];
        }

        // Сортируем по сумме (от меньшей к большей)
        usort($comparison, function ($a, $b) {
            return $a['total_amount'] <=> $b['total_amount'];
        });

        return $comparison;
    }

    /**
     * Выбрать лучшего поставщика на основе КП
     */
    public function selectBestSupplier(Collection $proposals): ?Supplier
    {
        if ($proposals->isEmpty()) {
            return null;
        }

        // Фильтруем действительные КП
        $validProposals = $proposals->filter(function ($proposal) {
            return !$proposal->isExpired() 
                && $proposal->status === \App\BusinessModules\Features\Procurement\Enums\SupplierProposalStatusEnum::SUBMITTED;
        });

        if ($validProposals->isEmpty()) {
            return null;
        }

        // Выбираем КП с наименьшей суммой
        $bestProposal = $validProposals->sortBy('total_amount')->first();

        return $bestProposal->supplier;
    }
}

