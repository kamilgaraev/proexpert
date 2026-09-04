<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Notifications\Services;

use App\Enums\Contract\ContractStatusEnum;

use function trans_message;

final class NotificationContentFormatter
{
    public function format(string $type, array $data): array
    {
        $businessType = $this->stringValue($data['type'] ?? null) ?? $type;
        $title = $this->stringValue($data['title'] ?? null);
        $message = $this->stringValue($data['message'] ?? null);

        if (! $this->isDisplayText($title, $businessType)) {
            $title = $this->defaultTitle($businessType, $data);
        }

        if (! $this->isDisplayText($message, $businessType)) {
            $message = $businessType === 'contract_status_changed'
                ? $this->contractStatusMessage($data)
                : '';
        }

        return [...$data, 'title' => $title, 'message' => $message];
    }

    private function defaultTitle(string $type, array $data): string
    {
        if ($type === 'contract_status_changed') {
            $contract = is_array($data['contract'] ?? null) ? $data['contract'] : [];
            $number = $this->stringValue($contract['number'] ?? $data['contract_number'] ?? null);

            return $number === null
                ? trans_message('notifications.content.contract_status_changed')
                : trans_message('notifications.content.contract_status_changed_number', ['number' => $number]);
        }

        return trans_message($type === 'contract_limit_warning'
            ? 'notifications.content.contract_limit_warning'
            : 'notifications.content.default_title');
    }

    private function contractStatusMessage(array $data): string
    {
        $newStatus = ContractStatusEnum::tryFrom($this->stringValue($data['new_status'] ?? null) ?? '');
        $oldStatus = ContractStatusEnum::tryFrom($this->stringValue($data['old_status'] ?? null) ?? '');

        if ($newStatus === null) {
            return trans_message('notifications.content.contract_status_details');
        }

        if ($oldStatus === null) {
            return trans_message('notifications.content.contract_new_status', ['status' => $newStatus->label()]);
        }

        return trans_message('notifications.content.contract_status_transition', [
            'old' => $oldStatus->label(),
            'new' => $newStatus->label(),
        ]);
    }

    private function isDisplayText(?string $value, string $type): bool
    {
        return $value !== null
            && $value !== $type
            && preg_match('/^[a-z][a-z0-9]*(?:[_.:][a-z0-9]+)+$/D', $value) !== 1;
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
