<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreDesignImpactReviewDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['expected_revision' => ['required', 'integer', 'min:1'], 'decision' => ['required', 'string', Rule::in(['keep_old', 'move_to_new', 'end'])], 'reason' => ['required', 'string', 'max:2000'], 'new_source_sheet_id' => ['nullable', 'integer', 'min:1'], 'new_source_element_id' => ['nullable', 'integer', 'min:1']];
    }
}
