<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreDesignCompositionRevisionRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array { return ['expected_state_version' => [\Illuminate\Validation\Rule::requiredIf(fn (): bool => $this->integer('expected_revision') > 0), 'integer', 'min:1'], 'composition' => ['required', 'array'], 'expected_revision' => ['required', 'integer', 'min:0']]; }
}
