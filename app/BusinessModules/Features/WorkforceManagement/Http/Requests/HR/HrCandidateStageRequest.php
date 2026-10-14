<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Http\Requests\HR;

use Illuminate\Foundation\Http\FormRequest;

final class HrCandidateStageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'stage' => ['required', 'string', 'max:80'], 'expected_stage' => ['required', 'string', 'max:80'], 'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
