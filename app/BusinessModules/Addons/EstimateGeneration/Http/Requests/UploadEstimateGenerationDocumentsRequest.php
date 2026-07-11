<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Requests;

use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\Concerns\AuthorizesEstimateGenerationRequest;
use Illuminate\Foundation\Http\FormRequest;

class UploadEstimateGenerationDocumentsRequest extends FormRequest
{
    use AuthorizesEstimateGenerationRequest;

    public function authorize(): bool
    {
        return $this->authorizeEstimateGeneration('estimate_generation.upload_documents');
    }

    public function rules(): array
    {
        return [
            'state_version' => ['required', 'integer', 'min:0'],
            'files' => ['required', 'array', 'min:1', 'max:10'],
            'files.*' => ['file', 'max:204800', 'mimes:pdf,jpg,jpeg,png,xlsx,xls,dwg,dxf'],
        ];
    }
}
