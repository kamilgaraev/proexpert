<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Landing;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class NotificationPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    public function rules(): array
    {
        $rules = ['organization_id' => ['prohibited'], 'user_id' => ['prohibited']];

        if (! $this->isMethod('GET')) {
            $rules += [
                'notification_type' => ['required', 'string', 'max:100'],
                'enabled_channels' => ['present', 'array', 'max:4'],
                'enabled_channels.*' => ['string', 'distinct', 'in:email,telegram,in_app,websocket'],
            ];
        }

        return $rules;
    }
}
