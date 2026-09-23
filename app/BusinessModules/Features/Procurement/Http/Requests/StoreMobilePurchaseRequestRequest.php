<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Http\Requests;

final class StoreMobilePurchaseRequestRequest extends StorePurchaseRequestRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['site_request_id'][0] = 'required';

        return $rules;
    }
}
