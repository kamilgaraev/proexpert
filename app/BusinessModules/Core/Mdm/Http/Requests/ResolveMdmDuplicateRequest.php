<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Http\Requests;

class ResolveMdmDuplicateRequest extends MdmRequest
{
    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', 'in:resolved,rejected'],
            'master_entity_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
