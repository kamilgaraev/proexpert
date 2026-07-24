<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Http\Requests;

class ListMdmChangeRequestsRequest extends MdmRequest
{
    public function rules(): array
    {
        return array_merge($this->paginationRules(), [
            'entity_type' => $this->entityTypeRule(false),
            'status' => ['nullable', 'string', 'max:60'],
            'priority' => ['nullable', 'string', 'in:low,normal,high,urgent'],
            'q' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
