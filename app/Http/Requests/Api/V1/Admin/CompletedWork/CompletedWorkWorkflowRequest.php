<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\CompletedWork;

use Illuminate\Foundation\Http\FormRequest;

final class CompletedWorkWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return match ($this->route('workflow_action')) {
            'reject' => ['reason' => ['required', 'string', 'max:2000']],
            'request_changes' => ['comment' => ['required', 'string', 'max:2000']],
            default => [],
        };
    }
}
