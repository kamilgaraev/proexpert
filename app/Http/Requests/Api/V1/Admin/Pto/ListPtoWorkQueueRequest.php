<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Pto;

use Illuminate\Foundation\Http\FormRequest;

final class ListPtoWorkQueueRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'project_id' => ['sometimes', 'integer', 'min:1'],
            'category' => ['sometimes', 'in:risk,problem,blocker,task'],
            'queue' => ['sometimes', 'in:mine,waiting,unassigned,overdue,risks,returns,incomplete'],
            'responsible_user_id' => ['sometimes', 'integer', 'min:1'],
            'action' => ['sometimes', 'string', 'max:64'],
            'horizon_days' => ['sometimes', 'integer', 'min:1', 'max:90'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
