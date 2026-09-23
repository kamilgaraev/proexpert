<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Mobile;

use Illuminate\Foundation\Http\FormRequest;

final class ApproveMobileTimeEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [];
    }
}
