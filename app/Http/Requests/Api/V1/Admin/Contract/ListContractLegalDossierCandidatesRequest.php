<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Contract;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class ListContractLegalDossierCandidatesRequest extends FormRequest
{
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

        return $authorization->can($actor, 'contracts.edit', $context)
            && $authorization->can($actor, 'legal_archive.update', $context);
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:160'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
            'organization_id' => ['prohibited'],
            'project_id' => ['prohibited'],
            'contract_id' => ['prohibited'],
            'document_id' => ['prohibited'],
        ];
    }
}
