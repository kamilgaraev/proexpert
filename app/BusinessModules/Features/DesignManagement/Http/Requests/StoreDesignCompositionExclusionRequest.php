<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreDesignCompositionExclusionRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array { return ['expected_state_version' => ['required', 'integer', 'min:1'], 'item_key' => ['required', 'string', 'max:255'], 'reason' => ['required', 'string', 'max:2000']]; }
}
