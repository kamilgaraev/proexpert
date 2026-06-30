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

        foreach (['norm_id', 'normative_code', 'reason', 'quantity', 'unit', 'quantity_basis', 'action', 'target_work_item_key', 'selection_source'] as $key) {
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
            'feedback_type' => ['required', 'string', Rule::in(['normative_rejection', 'normative_correction', 'normative_confirmation', 'quantity_confirmation', 'duplicate_resolution', 'work_item_resolution'])],
            'section_key' => ['nullable', 'string', 'max:255'],
            'work_item_key' => [
                'nullable',
                Rule::requiredIf(fn (): bool => in_array($this->input('feedback_type'), ['normative_rejection', 'normative_confirmation', 'quantity_confirmation', 'duplicate_resolution', 'work_item_resolution'], true)),
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
            'payload.action' => ['nullable', 'string', Rule::in(['remove_item', 'keep_item', 'merge_with_existing'])],
            'payload.target_work_item_key' => ['nullable', 'string', 'max:255'],
            'payload.selection_source' => ['nullable', 'string', Rule::in(['offered_candidate', 'catalog_search'])],
            'norm_id' => ['nullable', 'integer'],
            'normative_code' => ['nullable', 'string', 'max:100'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'quantity' => ['nullable', 'numeric', 'gt:0'],
            'unit' => ['nullable', 'string', 'max:50'],
            'quantity_basis' => ['nullable', 'string', 'max:2000'],
            'action' => ['nullable', 'string', Rule::in(['remove_item', 'keep_item', 'merge_with_existing'])],
            'target_work_item_key' => ['nullable', 'string', 'max:255'],
            'selection_source' => ['nullable', 'string', Rule::in(['offered_candidate', 'catalog_search'])],
            'response_scope' => ['nullable', 'string', Rule::in(['full', 'review_queue'])],
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

            if ($this->input('feedback_type') === 'normative_confirmation') {
                $payload = $this->input('payload', []);
                $hasNormId = is_array($payload) && ($payload['norm_id'] ?? null) !== null && $payload['norm_id'] !== '';
                $hasNormativeCode = is_array($payload)
                    && trim((string) ($payload['normative_code'] ?? '')) !== '';

                if (!$hasNormId && !$hasNormativeCode) {
                    $validator->errors()->add(
                        'payload.norm_id',
                        trans_message('estimate_generation.normative_confirmation_norm_required')
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

            if ($this->input('feedback_type') === 'work_item_resolution') {
                $payload = $this->input('payload', []);
                $action = is_array($payload) ? trim((string) ($payload['action'] ?? '')) : '';

                if ($action === '') {
                    $validator->errors()->add(
                        'payload.action',
                        trans_message('estimate_generation.work_item_resolution_action_required')
                    );
                }
            }
        });
    }
}
