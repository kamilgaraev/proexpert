<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Notifications\Services;

final class NotificationPayloadNormalizer
{
    public function normalize(string $type, array $data, string $notificationType): array
    {
        $businessType = $this->stringValue($data['type'] ?? null) ?? $type;
        $category = $this->stringValue($data['category'] ?? null)
            ?? $this->stringValue($data['notification_type'] ?? null)
            ?? $notificationType;
        $entity = $this->normalizeEntity($businessType, $data);

        return [
            ...$data,
            'type' => $businessType,
            'title' => $this->stringValue($data['title'] ?? null) ?? $type,
            'message' => $this->stringValue($data['message'] ?? null) ?? '',
            'category' => $category,
            'notification_type' => $this->stringValue($data['notification_type'] ?? null) ?? $notificationType,
            'interface' => $this->stringValue($data['interface'] ?? null) ?? 'admin',
            'entity' => $entity,
            'entity_type' => $this->stringValue($data['entity_type'] ?? null) ?? $entity['type'],
            'entity_id' => $data['entity_id'] ?? $entity['id'],
            'target_route' => $this->stringValue($data['target_route'] ?? $data['route'] ?? $data['url'] ?? null),
            'context' => $data['context'] ?? [],
            'project_id' => $data['project_id'] ?? null,
            'project_name' => $this->stringValue($data['project_name'] ?? null),
            'organization_id' => $data['organization_id'] ?? null,
        ];
    }

    private function normalizeEntity(string $businessType, array $data): array
    {
        if (isset($data['entity']) && is_array($data['entity'])) {
            return [
                'type' => $this->stringValue($data['entity']['type'] ?? null) ?? $this->inferEntityType($businessType),
                'id' => $data['entity']['id'] ?? $this->inferEntityId($data),
                ...$data['entity'],
            ];
        }

        return [
            'type' => $this->inferEntityType($businessType),
            'id' => $this->inferEntityId($data),
        ];
    }

    private function inferEntityType(string $businessType): string
    {
        if (str_contains($businessType, 'purchase_order')) {
            return 'purchase_order';
        }

        if (str_contains($businessType, 'purchase_request')) {
            return 'purchase_request';
        }

        if (str_contains($businessType, 'act_report')) {
            return 'act_report';
        }

        if (str_contains($businessType, 'site_request')) {
            return 'site_request';
        }

        return $businessType;
    }

    private function inferEntityId(array $data): mixed
    {
        foreach (['entity_id', 'request_id', 'order_id', 'act_id', 'contract_id', 'project_id'] as $key) {
            if (array_key_exists($key, $data)) {
                return $data[$key];
            }
        }

        return null;
    }

    private function stringValue(mixed $value): ?string
    {
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        return null;
    }
}
