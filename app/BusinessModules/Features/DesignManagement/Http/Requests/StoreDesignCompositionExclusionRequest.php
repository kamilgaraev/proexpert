<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreDesignCompositionExclusionRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array { return ['item_key' => ['required', 'string', 'max:255'], 'reason' => ['required', 'string', 'max:2000']]; }
}
