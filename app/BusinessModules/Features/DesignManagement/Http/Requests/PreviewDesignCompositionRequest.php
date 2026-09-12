<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class PreviewDesignCompositionRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array { return ['project_id' => ['required', 'integer'], 'project_stage' => ['required', 'in:pd,rd,survey,bim'], 'composition' => ['required', 'array']]; }
}
