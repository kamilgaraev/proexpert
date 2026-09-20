<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Contract;

use Illuminate\Foundation\Http\FormRequest;

final class SaveContractLibraryItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $revision = $this->route('libraryItem') !== null;

        return [
            'kind' => [$revision ? 'prohibited' : 'required', 'string', 'in:template,block,variable'],
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'array'],
            'request_key' => ['required', 'string', 'max:191'],
            'expected_version' => [$revision ? 'required' : 'prohibited', 'integer', 'min:1'],
        ];
    }
}
