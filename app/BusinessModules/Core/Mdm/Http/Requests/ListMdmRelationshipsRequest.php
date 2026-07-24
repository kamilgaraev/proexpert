<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Http\Requests;

class ListMdmRelationshipsRequest extends MdmRequest
{
    public function rules(): array
    {
        return array_merge($this->paginationRules(), [
            'source_type' => ['nullable', 'string', 'max:120'],
            'source_id' => ['nullable', 'integer'],
            'target_type' => ['nullable', 'string', 'max:120'],
            'target_id' => ['nullable', 'integer'],
        ]);
    }
}
