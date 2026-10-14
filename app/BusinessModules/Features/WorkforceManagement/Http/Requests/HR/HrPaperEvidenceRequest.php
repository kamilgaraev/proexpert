<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Http\Requests\HR;

use Illuminate\Foundation\Http\FormRequest;

final class HrPaperEvidenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'evidence_file_id' => ['required', 'integer'],
            'expected_revision' => ['required', 'integer', 'min:1'],
            'signed_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'paper_original_location' => ['required', 'string', 'max:500'],
        ];
    }
}
