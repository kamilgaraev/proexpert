<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Requests;

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
        return [
            'type' => ['required', 'string', 'in:cursor,select,camera'],
            'payload' => ['required', 'array', 'max:5'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $type = $this->input('type');
            $payload = $this->input('payload');
            if (! is_array($payload)) {
                return;
            }
            if (strlen((string) json_encode($payload)) > 1024) {
                $validator->errors()->add('payload', trans_message('design_bim.errors.event_payload_invalid'));

                return;
            }

            $rules = match ($type) {
                'cursor' => ['x', 'y', 'z'],
                'select' => ['model_version_id', 'element_id'],
                'camera' => ['position', 'target'],
                default => [],
            };
            if (array_diff(array_keys($payload), $rules) !== [] || array_diff($rules, array_keys($payload)) !== []) {
                $validator->errors()->add('payload', trans_message('design_bim.errors.event_payload_invalid'));

                return;
            }
            if ($type === 'cursor' && (! $this->coordinate($payload['x']) || ! $this->coordinate($payload['y']) || ! $this->coordinate($payload['z']))) {
                $validator->errors()->add('payload', trans_message('design_bim.errors.event_payload_invalid'));
            }
            if ($type === 'select' && (! is_int($payload['model_version_id']) || ($payload['element_id'] !== null && (! is_string($payload['element_id']) || strlen($payload['element_id']) > 255)))) {
                $validator->errors()->add('payload', trans_message('design_bim.errors.event_payload_invalid'));
            }
            if ($type === 'camera' && (! $this->vector($payload['position'] ?? null) || ! $this->vector($payload['target'] ?? null))) {
                $validator->errors()->add('payload', trans_message('design_bim.errors.event_payload_invalid'));
            }
        });
    }

    private function vector(mixed $value): bool
    {
        return is_array($value) && array_keys($value) === [0, 1, 2] && collect($value)->every(fn ($coordinate): bool => $this->coordinate($coordinate));
    }

    private function coordinate(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value);
    }
}
