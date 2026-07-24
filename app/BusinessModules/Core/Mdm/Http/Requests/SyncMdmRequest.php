<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Http\Requests;

class SyncMdmRequest extends MdmRequest
{
    public function rules(): array
    {
        return ['entity_type' => $this->entityTypeRule(false)];
    }
}
