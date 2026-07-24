<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\ActReport;

use Illuminate\Foundation\Http\FormRequest;

class BulkExportActReportsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'act_ids' => ['required', 'array', 'min:1'],
            'act_ids.*' => ['required', 'integer'],
        ];
    }
}
