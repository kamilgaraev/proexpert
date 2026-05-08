<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Activity;

use Illuminate\Foundation\Http\FormRequest;

class ActivityEventIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'actor_user_id' => ['sometimes', 'integer'],
            'target_user_id' => ['sometimes', 'integer'],
            'project_id' => ['sometimes', 'integer'],
            'module' => ['sometimes', 'string', 'max:80'],
            'event_type' => ['sometimes', 'string', 'max:120'],
            'action' => ['sometimes', 'string', 'max:40'],
            'result' => ['sometimes', 'string', 'max:40'],
            'severity' => ['sometimes', 'string', 'max:40'],
            'subject_type' => ['sometimes', 'string', 'max:120'],
            'subject_id' => ['sometimes', 'integer'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date'],
            'search' => ['sometimes', 'string', 'max:120'],
        ];
    }
}
