<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Http\Requests\Mobile;

final class ResolveMobileFindingRequest extends MobilePhotoEvidenceRequest
{
    public function rules(): array
    {
        return array_merge([
            'resolution_comment' => ['required', 'string', 'max:2000'],
        ], $this->photosRules());
    }
}
