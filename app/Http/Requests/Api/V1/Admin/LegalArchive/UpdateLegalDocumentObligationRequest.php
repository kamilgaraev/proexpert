<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\LegalArchive;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateLegalDocumentObligationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User
            && app(AuthorizationService::class)->can($user, 'legal_archive.update', [
                'organization_id' => (int) $this->attributes->get('current_organization_id'),
            ]);
    }

    public function rules(): array
    {
        return [
            'responsible_user_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['required', 'string', Rule::in(['completed'])],
            'evidence' => ['nullable', 'array', 'max:10'],
            'evidence.*.label' => ['nullable', 'string', 'max:255'],
            'evidence.*.url' => ['required_with:evidence', 'url', 'max:2000'],
        ];
    }
}
