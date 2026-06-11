<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CrmActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'owner_user_id' => ['nullable', 'integer'],
            'company_id' => ['nullable', 'uuid'],
            'contact_id' => ['nullable', 'uuid'],
            'lead_id' => ['nullable', 'uuid'],
            'deal_id' => ['nullable', 'uuid'],
            'type' => [$required, 'string', Rule::in(['call', 'email', 'meeting', 'task', 'note'])],
            'direction' => ['nullable', 'string', Rule::in(['incoming', 'outgoing'])],
            'status' => ['nullable', 'string', Rule::in(['planned', 'done', 'cancelled', 'overdue'])],
            'subject' => [$required, 'string', 'max:500'],
            'body' => ['nullable', 'string', 'max:4000'],
            'due_at' => ['nullable', 'date'],
            'completed_at' => ['nullable', 'date'],
            'result' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
