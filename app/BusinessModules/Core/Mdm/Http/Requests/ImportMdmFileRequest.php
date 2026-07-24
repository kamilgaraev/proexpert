<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Http\Requests;

class ImportMdmFileRequest extends MdmRequest
{
    public function rules(): array
    {
        return [
            'entity_type' => $this->entityTypeRule(),
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls'],
            'mapping' => ['nullable', 'array'],
        ];
    }
}
