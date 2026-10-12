<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Contract;

use Illuminate\Foundation\Http\FormRequest;

final class ResolveContractDraftContentRequest extends FormRequest
{
    public function authorize(): bool { return $this->user() !== null; }

    public function rules(): array
    {
        return ['base_revision' => ['required', 'integer', 'min:1'], 'content' => ['required', 'array:document,variables'],
            'content.document' => ['required', 'array'], 'content.variables' => ['present', 'array', 'max:500'],
            'content.variables.*' => ['integer', 'min:1']];
    }
}
