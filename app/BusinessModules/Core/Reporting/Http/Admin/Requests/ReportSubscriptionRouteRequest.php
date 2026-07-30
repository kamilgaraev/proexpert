<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Requests;

class ReportSubscriptionRouteRequest extends ReportFormRequest
{
    public function validationData(): array
    {
        return [...parent::validationData(), '_subscription_id' => $this->route('subscriptionId')];
    }

    public function rules(): array
    {
        return ['_subscription_id' => ['required', 'string', ...$this->canonicalUlidRules()], ...$this->subscriptionForbiddenClientFieldsRules()];
    }

    protected function acceptedBodyFields(): array
    {
        return [];
    }

    protected function safeValidationFieldKey(string $field): string
    {
        return $field === '_subscription_id' ? 'subscription_id' : parent::safeValidationFieldKey($field);
    }

    public function routeId(): string
    {
        return (string) $this->validated('_subscription_id');
    }
}
