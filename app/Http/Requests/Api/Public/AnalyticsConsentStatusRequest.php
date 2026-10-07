<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Public;

use Illuminate\Foundation\Http\FormRequest;

final class AnalyticsConsentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['visitor_id' => ['required', 'uuid'], 'receipt_id' => ['required', 'uuid']];
    }
}
