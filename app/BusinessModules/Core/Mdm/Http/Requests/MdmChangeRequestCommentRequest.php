<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Http\Requests;

class MdmChangeRequestCommentRequest extends MdmRequest
{
    public function rules(): array
    {
        return [
            'comment' => ['nullable', 'string', 'max:1000'],
            'note' => ['nullable', 'string', 'max:1000'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
