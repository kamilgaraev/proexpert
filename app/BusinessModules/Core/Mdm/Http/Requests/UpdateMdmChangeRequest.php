<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Http\Requests;

use App\BusinessModules\Core\Mdm\Services\MdmEntityRegistry;
use Illuminate\Validation\Rule;

class UpdateMdmChangeRequest extends MdmRequest
{
    public function rules(): array
    {
        return [
            'entity_type' => ['sometimes', 'string', Rule::in(array_keys(app(MdmEntityRegistry::class)->all()))],
            'entity_id' => ['nullable', 'integer'],
            'action' => ['sometimes', 'string', 'in:create,update'],
            'priority' => ['nullable', 'string', 'in:low,normal,high,urgent'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'business_justification' => ['nullable', 'string', 'max:4000'],
            'idempotency_key' => ['nullable', 'string', 'max:160'],
            'proposed_values' => ['required', 'array'],
        ];
    }
}
