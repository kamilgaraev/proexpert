<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Contract;

use Illuminate\Foundation\Http\FormRequest;

final class EnableContractOrganizationViewsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['fingerprint' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'], 'reason' => ['required', 'string', 'max:2000']];
    }
}
