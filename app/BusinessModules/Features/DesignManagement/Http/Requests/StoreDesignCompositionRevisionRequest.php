<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreDesignCompositionRevisionRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array { return ['composition' => ['required', 'array'], 'expected_revision' => ['required', 'integer', 'min:0']]; }
}
