<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Contract;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UploadContractBuilderAssetRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return ['file' => ['required', 'file', 'max:20480'], 'kind' => ['required', Rule::in(['attachment', 'evidence'])], 'request_key' => ['required', 'string', 'max:191']];
    }
}
