<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\LegalArchive;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class RecoverLegalArchiveDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user instanceof User) {
            return false;
        }
        $context = ['organization_id' => (int) $this->attributes->get('current_organization_id')];
        $authorization = app(AuthorizationService::class);

        return $authorization->can($user, 'legal_archive.create', $context)
            && (! $this->hasFile('file') || (
                $authorization->can($user, 'legal_archive.files.upload', $context)
                && $authorization->can($user, 'legal_archive.versions.create', $context)
            ));
    }

    public function rules(): array
    {
        return ['file' => ['nullable', 'file', 'max:102400']];
    }
}
