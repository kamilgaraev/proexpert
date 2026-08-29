<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Http\Requests;

use App\Rules\ProjectAccessibleRule;
use Illuminate\Foundation\Http\FormRequest;

final class ContractPaymentAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['nullable', 'integer', new ProjectAccessibleRule],
        ];
    }

    public function projectId(): ?int
    {
        $projectId = $this->validated('project_id');

        return $projectId !== null ? (int) $projectId : null;
    }
}
