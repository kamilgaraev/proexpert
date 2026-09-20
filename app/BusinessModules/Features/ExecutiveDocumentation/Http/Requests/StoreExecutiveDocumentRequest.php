<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Http\Requests;

use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentInput;
use Illuminate\Foundation\Http\FormRequest;

final class StoreExecutiveDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return app(ExecutiveDocumentInput::class)->rules();
    }
}
