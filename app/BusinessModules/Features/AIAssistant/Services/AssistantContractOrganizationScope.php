<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\Enums\Contract\ContractStatusEnum;
use App\Services\Contract\ContractOrganizationListScope;
use Illuminate\Database\Eloquent\Builder;

final class AssistantContractOrganizationScope
{
    public function apply(Builder $query, int $organizationId, bool $activePlacementOnly = false): void
    {
        $filters = $activePlacementOnly ? [] : ['status' => array_column(ContractStatusEnum::cases(), 'value')];
        (new ContractOrganizationListScope)->apply($query, $organizationId, $filters, static function (Builder $legacy) use ($organizationId): void {
            $legacy->where(static fn (Builder $parties): Builder => $parties->where('contracts.organization_id', $organizationId)
                ->orWhereHas('contractor', static fn (Builder $counterparty): Builder => $counterparty->where('source_organization_id', $organizationId))
                ->orWhereHas('parties', static fn (Builder $party): Builder => $party->where('linked_organization_id', $organizationId)));
        });
    }
}
