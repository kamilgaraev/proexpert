<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreDesignSourceLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['source_version_id' => ['required', 'integer'], 'source_sheet_id' => ['nullable', 'integer'], 'source_element_id' => ['nullable', 'integer', 'min:1'], 'target_type' => ['required', 'string', Rule::in(['estimate_item', 'schedule_task', 'construction_journal_entry', 'completed_work', 'purchase_request', 'executive_document'])], 'target_id' => ['required', 'integer']];
    }
}
