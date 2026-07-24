<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Http\Requests;

class ImportMdmRowsRequest extends MdmRequest
{
    public function rules(): array
    {
        return [
            'entity_type' => $this->entityTypeRule(),
            'rows' => ['required', 'array'],
            'source' => ['nullable', 'string', 'max:120'],
        ];
    }
}
