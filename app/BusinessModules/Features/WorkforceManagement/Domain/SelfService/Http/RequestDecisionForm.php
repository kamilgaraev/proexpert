<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Domain\SelfService\Http;

use Illuminate\Foundation\Http\FormRequest;

final class RequestDecisionForm extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'in:approve,reject,comment,hr_approve,hr_reject'],
            'basis' => ['required', 'string', 'max:1000'],
            'basis_reference' => ['required_if:action,hr_approve', 'string', 'max:255'],
            'basis_document_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
