<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Http\Requests;

class ReviewMdmChangeRequest extends MdmRequest
{
    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', 'in:approved,rejected'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
