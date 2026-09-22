<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class MarkExecutiveDocumentRequirementNotApplicableRequest extends FormRequest
{
    public function rules(): array { return ['reason' => ['required', 'string', 'min:3', 'max:2000'], 'expected_revision' => ['required', 'integer', 'min:1']]; }
}
