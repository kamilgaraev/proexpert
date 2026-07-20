<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\LegalArchive;

use Illuminate\Foundation\Http\FormRequest;

final class DecideLegalArchiveWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'max:191'],
            'instance_lock_version' => ['required', 'integer', 'min:0'],
            'step_lock_version' => ['required', 'integer', 'min:0'],
            'comment' => ['nullable', 'string', 'max:5000'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'target_actor_type' => ['nullable', 'string', 'max:64'],
            'target_actor_id' => ['nullable', 'string', 'max:191'],
            'due_at' => ['nullable', 'date'],
        ];
    }
}
