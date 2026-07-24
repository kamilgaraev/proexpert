<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Http\Requests;

use Illuminate\Validation\Rule;

class AssignMdmOwnerRequest extends MdmRequest
{
    public function rules(): array
    {
        return [
            'owner_user_id' => [
                'nullable',
                'integer',
                Rule::exists('organization_user', 'user_id')->where('organization_id', $this->organizationId()),
            ],
        ];
    }
}
