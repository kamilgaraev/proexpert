<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Http\Requests;

use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentImportService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

final class ExecutiveDocumentImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return match ($this->route()?->getActionMethod()) {
            'store' => ['operation_key' => ['required', 'string', 'max:80'], 'files' => ['required', 'array', 'min:1', 'max:100']],
            'upload' => ['file' => ['required', File::types(ExecutiveDocumentImportService::EXTENSIONS)->max(25 * 1024)]],
            'map' => ['rows' => ['required', 'array', 'min:1', 'max:100'], 'rows.*.id' => ['required', 'integer', 'distinct'], 'rows.*.mapping' => ['required', 'array']],
            'start' => ['retry_failed_only' => ['sometimes', 'boolean']],
            default => [],
        };
    }
}
