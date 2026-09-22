<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RejectExecutiveDocumentRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return ['comment' => ['required', 'string', 'max:1000'], 'version_id' => ['required', 'integer', 'min:1']];
    }
}
