<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\ErpControls;

use App\Services\ErpControls\ErpControlRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ErpControlConflictIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'domain' => ['nullable', 'string', Rule::in(ErpControlRegistry::DOMAINS)],
            'operation' => ['nullable', 'string', 'max:160'],
            'risk_level' => ['nullable', 'string', Rule::in(ErpControlRegistry::RISK_LEVELS)],
            'actor_user_id' => ['nullable', 'integer', 'min:1'],
            'entity_type' => ['nullable', 'string', 'max:160'],
            'entity_id' => ['nullable'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
