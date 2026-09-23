<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Http\Requests;

use App\BusinessModules\Features\HandoverAcceptance\Http\Requests\Mobile\MobilePhotoEvidenceRequest;
use Illuminate\Validation\Rule;

final class StoreMobileFindingRequest extends MobilePhotoEvidenceRequest
{
    public function rules(): array
    {
        return array_merge([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'severity' => ['required', 'string', Rule::in(['minor', 'major', 'critical'])],
            'create_quality_defect' => ['required', 'boolean'],
            'quality_defect_inspection_required' => ['required_if:create_quality_defect,true', 'boolean'],
            'work_rework_id' => ['nullable', 'integer', 'min:1'],
        ], $this->photosRules());
    }
}
