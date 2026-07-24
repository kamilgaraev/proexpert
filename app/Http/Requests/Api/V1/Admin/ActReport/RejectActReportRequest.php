<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\ActReport;

use Illuminate\Foundation\Http\FormRequest;

class RejectActReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
