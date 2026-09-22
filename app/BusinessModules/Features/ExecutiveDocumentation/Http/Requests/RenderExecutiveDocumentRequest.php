<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Http\Requests;

use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentRenderService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RenderExecutiveDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['template_version' => ['required', 'string', Rule::in([ExecutiveDocumentRenderService::TEMPLATE_VERSION])]];
    }
}
