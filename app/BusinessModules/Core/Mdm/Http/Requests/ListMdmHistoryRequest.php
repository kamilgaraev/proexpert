<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Http\Requests;

class ListMdmHistoryRequest extends MdmRequest
{
    public function rules(): array
    {
        return array_merge($this->paginationRules(), [
            'entity_type' => $this->entityTypeRule(false),
            'entity_id' => ['nullable', 'integer'],
        ]);
    }
}
