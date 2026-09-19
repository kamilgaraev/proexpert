<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Contract;

use App\Models\ContractOrganizationView;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ContractOrganizationViewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $view = $this->resource;
        if (!$view instanceof ContractOrganizationView) {
            throw new \LogicException('Contract organization view required');
        }
        $contract = $view->contract;
        $partyFields = ['role', 'linked_organization_id', 'name', 'legal_name', 'inn', 'kpp', 'ogrn', 'legal_address'];

        return [
            'id' => $view->id, 'contract_id' => $view->contract_id, 'organization_id' => $view->organization_id,
            'version' => $view->version, 'visibility' => $view->visibility, 'private_notes' => $view->private_notes,
            'legal_contract' => [
                'id' => $contract->id, 'project_id' => $contract->project_id, 'number' => $contract->number,
                'date' => $contract->date?->toDateString(), 'subject' => $contract->subject,
                'first_party' => $contract->firstParty?->only($partyFields),
                'second_party' => $contract->secondParty?->only($partyFields),
                'currency' => $contract->currency, 'amount' => $contract->total_amount,
            ],
        ];
    }
}
