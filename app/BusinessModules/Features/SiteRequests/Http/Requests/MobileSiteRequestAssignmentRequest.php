<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SiteRequests\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class MobileSiteRequestAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['assigned_user_id' => ['required', 'integer', 'min:1']];
    }
}
