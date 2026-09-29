<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Requests;

use App\BusinessModules\Features\DesignManagement\Services\DesignModelSessionPayloadValidator as Payload;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class StoreDesignModelSessionTransientEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $versioned = $this->input('schema_version') !== null;

        return [
            'schema_version' => ['sometimes', 'integer', 'in:2'],
            'client_id' => [$versioned ? 'required' : 'sometimes', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/'],
            'sequence' => [$versioned ? 'required' : 'sometimes', 'integer', 'min:0', 'max:9007199254740991'],
            'type' => ['required', 'string', 'in:cursor,select,camera,view,heartbeat,leave'],
            'payload' => ['present', 'nullable', 'array', 'max:7'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $type = $this->input('type');
            $payload = $this->input('payload');
            $valid = strlen((string) json_encode($payload)) <= 4096 && match ($type) {
                'camera' => Payload::camera($payload, $this->input('schema_version') === null),
                'cursor' => $payload === null || (is_array($payload) && count($payload) === 3
                    && Payload::coordinate($payload['x'] ?? null) && Payload::coordinate($payload['y'] ?? null)
                    && Payload::coordinate($payload['z'] ?? null)),
                'select' => Payload::selection($payload, $this->input('schema_version') === null),
                'view' => is_array($payload) && array_keys($payload) === ['revision']
                    && is_int($payload['revision']) && $payload['revision'] > 0,
                'heartbeat', 'leave' => $payload === null || $payload === [],
                default => false,
            };
            if (! $valid) {
                $validator->errors()->add('payload', trans_message('design_bim.errors.event_payload_invalid'));
            }
        });
    }
}
