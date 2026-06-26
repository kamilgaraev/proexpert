<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\ErpControls;

use App\Services\ErpControls\ErpControlRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ErpControlPolicyIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'domain' => ['nullable', 'string', Rule::in(ErpControlRegistry::DOMAINS)],
            'risk_level' => ['nullable', 'string', Rule::in(ErpControlRegistry::RISK_LEVELS)],
            'operation' => ['nullable', 'string', 'max:160'],
        ];
    }
}
