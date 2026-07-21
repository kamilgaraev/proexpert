<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Contract;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class MutateContractLegalDossierRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $metadata = $this->input('metadata');
        if (! is_string($metadata)) {
            return;
        }

        $decoded = json_decode($metadata, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $this->merge(['metadata' => $decoded]);
        }
    }

    public function authorize(): bool
    {
        $actor = $this->user();
        if (! $actor instanceof User) {
            return false;
        }

        $context = [
            'organization_id' => (int) $this->attributes->get('current_organization_id'),
            'project_id' => (int) $this->route('project'),
        ];
        $authorization = app(AuthorizationService::class);
        if (! $authorization->can($actor, 'contracts.edit', $context)) {
            return false;
        }

        return match ($this->input('action')) {
            'create' => $authorization->can($actor, 'legal_archive.create', $context),
            'attach' => $authorization->can($actor, 'legal_archive.update', $context),
            default => false,
        };
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in(['create', 'attach'])],
            'idempotency_key' => ['required_if:action,create', 'prohibited_unless:action,create', 'uuid'],
            'title' => ['required_if:action,create', 'prohibited_unless:action,create', 'string', 'max:512'],
            'document_number' => ['nullable', 'prohibited_unless:action,create', 'string', 'max:255'],
            'document_date' => ['nullable', 'prohibited_unless:action,create', 'date'],
            'description' => ['nullable', 'prohibited_unless:action,create', 'string', 'max:5000'],
            'metadata' => ['nullable', 'prohibited_unless:action,create', 'array'],
            'document_id' => ['required_if:action,attach', 'prohibited_unless:action,attach', 'integer', 'min:1'],
            'organization_id' => ['prohibited'],
            'project_id' => ['prohibited'],
            'primary_project_id' => ['prohibited'],
            'contract_id' => ['prohibited'],
            'document_type' => ['prohibited'],
            'type_profile_code' => ['prohibited'],
            'source_type' => ['prohibited'],
            'source_id' => ['prohibited'],
            'source_idempotency_key' => ['prohibited'],
            'links' => ['prohibited'],
        ];
    }
}
