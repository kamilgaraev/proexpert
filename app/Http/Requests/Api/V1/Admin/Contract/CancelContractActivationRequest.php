<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Contract;

use Illuminate\Foundation\Http\FormRequest;

final class CancelContractActivationRequest extends FormRequest
{
    public function authorize(): bool { return $this->user() !== null; }

    public function rules(): array
    {
        return ['basis' => ['required', 'string', 'max:2000'], 'request_key' => ['required', 'string', 'max:191']];
    }
}
