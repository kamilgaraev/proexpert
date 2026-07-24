<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Http\Requests;

class MergeMdmDuplicateRequest extends MdmRequest
{
    public function rules(): array
    {
        return ['master_entity_id' => ['required', 'integer']];
    }
}
