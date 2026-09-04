<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Landing\User;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class OrganizationTeamAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $organizationId = (int) $this->attributes->get('current_organization_id');

        return $actor instanceof User && $organizationId > 0
            && app(AuthorizationService::class)->can($actor, 'users.manage', ['organization_id' => $organizationId]);
    }

    public function rules(): array
    {
        return ['is_active' => ['required', 'boolean']];
    }
}
