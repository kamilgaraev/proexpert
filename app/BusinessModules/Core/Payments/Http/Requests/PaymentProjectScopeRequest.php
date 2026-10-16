<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Http\Requests;

use App\Rules\ProjectAccessibleRule;
use Illuminate\Foundation\Http\FormRequest;

final class PaymentProjectScopeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (int) $this->attributes->get('current_organization_id') > 0;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['nullable', 'integer', 'min:1', new ProjectAccessibleRule((int) $this->attributes->get('current_organization_id'), includeArchived: true)],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ];
    }

    public function projectId(): ?int
    {
        $value = $this->validated('project_id');

        return $value === null ? null : (int) $value;
    }
}
