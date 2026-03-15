<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\VideoMonitoring\Http\Requests;

class UpdateVideoCameraRequest extends StoreVideoCameraRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['name'][0] = 'sometimes';
        $rules['source_type'][0] = 'sometimes';

        return $rules;
    }
}
