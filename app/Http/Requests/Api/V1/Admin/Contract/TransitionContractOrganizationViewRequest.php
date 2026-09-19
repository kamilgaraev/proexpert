<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Contract;

use Illuminate\Foundation\Http\FormRequest;

final class TransitionContractOrganizationViewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'string', 'in:archive,unarchive,trash,restore'],
            'version' => ['required', 'integer', 'min:1'],
        ];
    }
}
