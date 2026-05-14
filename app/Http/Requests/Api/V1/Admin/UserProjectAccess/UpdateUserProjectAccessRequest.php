<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\UserProjectAccess;

use App\Enums\UserProjectAccessMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserProjectAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $organizationId = (int) ($this->attributes->get('current_organization_id')
            ?? $this->user()?->current_organization_id
            ?? 0);

        return [
            'project_access_mode' => [
                'required',
                'string',
                Rule::in(array_map(
                    fn (UserProjectAccessMode $mode): string => $mode->value,
                    UserProjectAccessMode::cases()
                )),
            ],
            'project_ids' => [
                'array',
                'required_if:project_access_mode,' . UserProjectAccessMode::ASSIGNED_PROJECTS->value,
            ],
            'project_ids.*' => [
                'integer',
                Rule::exists('projects', 'id')->where(static function ($query) use ($organizationId): void {
                    $query
                        ->where('organization_id', $organizationId)
                        ->whereNull('deleted_at');
                }),
            ],
        ];
    }
}
