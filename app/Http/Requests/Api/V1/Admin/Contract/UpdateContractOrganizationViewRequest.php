<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Contract;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateContractOrganizationViewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['private_notes' => ['present', 'nullable', 'string', 'max:20000'], 'version' => ['required', 'integer', 'min:1']];
    }
}
