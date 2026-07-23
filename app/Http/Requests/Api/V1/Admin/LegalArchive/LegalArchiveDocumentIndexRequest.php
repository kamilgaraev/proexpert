<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\LegalArchive;

use App\Models\User;
use App\Services\LegalArchive\LegalArchiveDictionary;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class LegalArchiveDocumentIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:160'],
            'search' => ['nullable', 'string', 'max:160'],
            'document_type' => ['nullable', 'string', Rule::in(LegalArchiveDictionary::values('types'))],
            'status' => ['nullable', 'string', Rule::in(LegalArchiveDictionary::values('statuses'))],
            'direction' => ['nullable', 'string', Rule::in(LegalArchiveDictionary::values('directions'))],
            'project_id' => ['nullable', 'integer', 'min:1'],
            'counterparty' => ['nullable', 'string', 'max:255'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'retention_until_from' => ['nullable', 'date'],
            'retention_until_to' => ['nullable', 'date', 'after_or_equal:retention_until_from'],
            'legal_hold' => ['nullable', 'boolean'],
            'link_type' => ['nullable', 'string', Rule::in(LegalArchiveDictionary::values('link_types'))],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
            'sort_by' => ['nullable', Rule::in(['document_date', 'created_at', 'updated_at', 'title', 'status', 'retention_until'])],
            'sort_direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ];
    }
}
