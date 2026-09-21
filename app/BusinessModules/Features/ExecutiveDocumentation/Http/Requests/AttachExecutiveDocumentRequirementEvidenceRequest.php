<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AttachExecutiveDocumentRequirementEvidenceRequest extends FormRequest
{
    public function rules(): array { return ['version_id' => ['required', 'integer'], 'coverage' => ['required', 'array'], 'expected_revision' => ['required', 'integer', 'min:1']]; }
}
