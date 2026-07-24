<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Http\Requests;

class ListMdmDuplicatesRequest extends MdmRequest
{
    public function rules(): array
    {
        return array_merge($this->paginationRules(), [
            'entity_type' => $this->entityTypeRule(false),
            'status' => ['nullable', 'string', 'max:60'],
        ]);
    }
}
