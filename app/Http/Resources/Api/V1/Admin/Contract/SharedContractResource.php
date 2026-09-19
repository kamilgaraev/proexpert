<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Contract;

use App\Models\Contract;
use App\Services\Contract\ContractSideResolverService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class SharedContractResource extends JsonResource
{
    public function __construct(Contract $resource, private readonly ?int $perspectiveOrganizationId = null)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $contract = $this->resource;
        if (!$contract instanceof Contract) {
            throw new \LogicException('Contract required');
        }
        $organizationId = $this->perspectiveOrganizationId
            ?? (int) ($request->attributes->get('current_organization_id') ?? $request->user()?->current_organization_id);

        return [
            'id' => $contract->id, 'organization_id' => $contract->organization_id,
            'project_id' => $contract->project_id, 'number' => $contract->number,
            'date' => $contract->date?->toDateString(), 'subject' => $contract->subject,
            'status' => $contract->status?->value, 'contract_side_type' => $contract->contract_side_type?->value,
            'currency' => $contract->currency, 'total_amount' => $contract->total_amount,
            'start_date' => $contract->start_date?->toDateString(), 'end_date' => $contract->end_date?->toDateString(),
            'contract_side' => app(ContractSideResolverService::class)->resolve($contract, $organizationId),
            'is_shared' => true, 'can_edit_owner_data' => false,
        ];
    }
}
