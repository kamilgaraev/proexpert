<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Requests;

use App\BusinessModules\Core\Reporting\Domain\ValueObjects\IdempotencyKey;

final class RunReportSubscriptionNowRequest extends ReportSubscriptionRouteRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            '_idempotency_key' => ['required', 'string', 'min:8', 'max:128', 'regex:/^[\x20-\x7E]+$/'],
        ];
    }

    public function validationData(): array
    {
        return [...parent::validationData(), '_idempotency_key' => $this->header('Idempotency-Key')];
    }

    protected function safeValidationFieldKey(string $field): string
    {
        return $field === '_idempotency_key' ? 'token' : parent::safeValidationFieldKey($field);
    }

    public function idempotencyKey(): IdempotencyKey
    {
        return new IdempotencyKey((string) $this->validated('_idempotency_key'));
    }
}
