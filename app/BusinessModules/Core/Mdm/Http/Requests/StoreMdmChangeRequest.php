<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Http\Requests;

class StoreMdmChangeRequest extends MdmRequest
{
    public function rules(): array
    {
        return [
            'entity_type' => $this->entityTypeRule(),
            'entity_id' => ['nullable', 'integer'],
            'action' => ['required', 'string', 'in:create,update'],
            'priority' => ['nullable', 'string', 'in:low,normal,high,urgent'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'business_justification' => ['nullable', 'string', 'max:4000'],
            'idempotency_key' => ['nullable', 'string', 'max:160'],
            'proposed_values' => ['required', 'array'],
        ];
    }
}
