<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Brigades;

use Illuminate\Foundation\Http\FormRequest;

class StoreBrigadeDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'document_type' => ['required', 'string', 'max:100'],
            'document' => ['required', 'file', 'max:10240'],
        ];
    }
}
