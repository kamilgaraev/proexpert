<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Http\Requests\Mobile;

use Illuminate\Validation\Rule;

final class ReviewChecklistItemRequest extends MobilePhotoEvidenceRequest
{
    public function rules(): array
    {
        return array_merge([
            'status' => ['required', 'string', Rule::in(['accepted', 'rejected'])],
            'comment' => ['required_if:status,rejected', 'nullable', 'string', 'max:1000'],
        ], $this->photosRules());
    }
}
