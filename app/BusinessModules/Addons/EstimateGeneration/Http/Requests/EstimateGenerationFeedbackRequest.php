<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class EstimateGenerationFeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $payload = $this->input('payload');

        if ($payload !== null && !is_array($payload)) {
            return;
        }

        $payload = $payload ?? [];

        foreach (['norm_id', 'normative_code', 'reason', 'quantity', 'unit', 'quantity_basis', 'action'] as $key) {
            if (!array_key_exists($key, $payload) && $this->filled($key)) {
                $payload[$key] = $this->input($key);
            }
        }

        if ($payload !== []) {
            $this->merge(['payload' => $payload]);
        }
    }

    public function rules(): array
    {
        return [
            'feedback_type' => ['required', 'string', Rule::in(['normative_rejection', 'normative_correction', 'quantity_confirmation', 'duplicate_resolution'])],
            'section_key' => ['nullable', 'string', 'max:255'],
            'work_item_key' => [
                'nullable',
                Rule::requiredIf(fn (): bool => in_array($this->input('feedback_type'), ['normative_rejection', 'quantity_confirmation', 'duplicate_resolution'], true)),
                'string',
                'max:255',
            ],
            'payload' => ['nullable', 'array'],
            'payload.norm_id' => ['nullable', 'integer'],
            'payload.normative_code' => ['nullable', 'string', 'max:100'],
            'payload.reason' => ['nullable', 'string', 'max:1000'],
            'payload.quantity' => ['nullable', 'numeric', 'gt:0'],
            'payload.unit' => ['nullable', 'string', 'max:50'],
            'payload.quantity_basis' => ['nullable', 'string', 'max:2000'],
            'payload.action' => ['nullable', 'string', Rule::in(['remove_item', 'keep_item'])],
            'norm_id' => ['nullable', 'integer'],
            'normative_code' => ['nullable', 'string', 'max:100'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'quantity' => ['nullable', 'numeric', 'gt:0'],
            'unit' => ['nullable', 'string', 'max:50'],
            'quantity_basis' => ['nullable', 'string', 'max:2000'],
            'action' => ['nullable', 'string', Rule::in(['remove_item', 'keep_item'])],
            'comments' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('feedback_type') === 'normative_rejection') {
                $payload = $this->input('payload', []);
                $hasNormId = is_array($payload) && ($payload['norm_id'] ?? null) !== null && $payload['norm_id'] !== '';
                $hasNormativeCode = is_array($payload)
                    && trim((string) ($payload['normative_code'] ?? '')) !== '';

                if (!$hasNormId && !$hasNormativeCode) {
                    $validator->errors()->add(
                        'payload.norm_id',
                        trans_message('estimate_generation.normative_feedback_norm_required')
                    );
                }
            }

            if ($this->input('feedback_type') === 'quantity_confirmation') {
                $payload = $this->input('payload', []);
                $quantity = is_array($payload) ? $payload['quantity'] ?? null : null;

                if ($quantity === null || $quantity === '') {
                    $validator->errors()->add(
                        'payload.quantity',
                        trans_message('estimate_generation.quantity_confirmation_quantity_required')
                    );
                }
            }

            if ($this->input('feedback_type') === 'duplicate_resolution') {
                $payload = $this->input('payload', []);
                $action = is_array($payload) ? trim((string) ($payload['action'] ?? '')) : '';

                if ($action === '') {
                    $validator->errors()->add(
                        'payload.action',
                        trans_message('estimate_generation.duplicate_resolution_action_required')
                    );
                }
            }
        });
    }
}
