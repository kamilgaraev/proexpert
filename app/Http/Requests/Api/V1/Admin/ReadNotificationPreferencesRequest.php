<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class ReadNotificationPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    public function rules(): array
    {
        return ['organization_id' => ['prohibited'], 'user_id' => ['prohibited']];
    }
}
