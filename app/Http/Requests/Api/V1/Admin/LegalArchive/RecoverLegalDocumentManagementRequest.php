<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\LegalArchive;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class RecoverLegalDocumentManagementRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user instanceof User) {
            return false;
        }

        return app(AuthorizationService::class)->can(
            $user,
            'legal_archive.security_recovery.manage',
            ['organization_id' => (int) $this->attributes->get('current_organization_id')],
        );
    }

    public function rules(): array
    {
        return [
            'successor_user_id' => ['required', 'integer', 'min:1'],
            'lock_version' => ['required', 'integer', 'min:0'],
        ];
    }
}
