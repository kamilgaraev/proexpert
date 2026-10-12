<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Contract;

final class RecordExternalContractClauseRequest extends DecideContractClauseRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'party_key' => ['required', 'string', 'max:36'],
            'basis' => ['required', 'string', 'max:2000'], 'asset_id' => ['required', 'integer', 'min:1']];
    }
}
