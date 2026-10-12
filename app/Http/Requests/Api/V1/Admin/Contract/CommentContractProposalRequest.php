<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Contract;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CommentContractProposalRequest extends FormRequest
{
    public function authorize(): bool { return $this->user() !== null; }

    public function rules(): array
    {
        return ['content_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'], 'request_key' => ['required', 'string', 'max:191'],
            'clause_id' => [Rule::requiredIf($this->boolean('blocking')), 'nullable', 'string', 'regex:/^[A-Za-z0-9_-]{1,512}$/D'],
            'body' => ['required', 'string', 'max:2000'], 'blocking' => ['sometimes', 'boolean']];
    }
}
