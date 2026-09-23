<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Mobile;

use Illuminate\Foundation\Http\FormRequest;

final class MobileCatalogTenderIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:64'],
            'risk_level' => ['nullable', 'string', 'max:64'],
            'priority' => ['nullable', 'string', 'max:64'],
            'source_id' => ['nullable', 'uuid'],
            'owner_user_id' => ['nullable', 'integer'],
            'go_no_go_decision' => ['nullable', 'string', 'max:64'],
            'overdue' => ['nullable', 'boolean'],
            'deadline' => ['nullable', 'in:today,week'],
            'archived' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'project_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
