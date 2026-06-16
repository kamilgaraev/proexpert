<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\CommercialProposals\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CommercialProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'number' => ['nullable', 'string', 'max:64'],
            'template_id' => ['nullable', 'uuid'],
            'title' => ['required', 'string', 'max:255'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:64'],
            'crm_deal_id' => ['nullable', 'uuid'],
            'tender_id' => ['nullable', 'uuid'],
            'presale_estimate_id' => ['nullable', 'uuid'],
            'project_id' => ['nullable', 'integer'],
            'contract_id' => ['nullable', 'integer'],
            'currency' => ['nullable', 'string', 'size:3'],
            'valid_until' => ['nullable', 'date'],
            'terms' => ['nullable', 'array'],
            'source_links' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
            'sections' => ['nullable', 'array'],
            'sections.*.title' => ['required_with:sections', 'string', 'max:255'],
            'sections.*.body' => ['nullable', 'string'],
            'sections.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'sections.*.metadata' => ['nullable', 'array'],
            'line_items' => ['nullable', 'array'],
            'line_items.*.section_index' => ['nullable', 'integer', 'min:0'],
            'line_items.*.title' => ['required_with:line_items', 'string', 'max:255'],
            'line_items.*.description' => ['nullable', 'string'],
            'line_items.*.unit' => ['nullable', 'string', 'max:32'],
            'line_items.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'line_items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'line_items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'line_items.*.vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'line_items.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'line_items.*.metadata' => ['nullable', 'array'],
        ];
    }
}
