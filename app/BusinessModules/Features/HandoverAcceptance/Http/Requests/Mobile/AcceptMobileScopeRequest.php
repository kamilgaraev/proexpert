<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Http\Requests\Mobile;

final class AcceptMobileScopeRequest extends MobilePhotoEvidenceRequest
{
    public function rules(): array
    {
        return array_merge([
            'comment' => ['nullable', 'string', 'max:1000'],
        ], $this->photosRules());
    }
}
