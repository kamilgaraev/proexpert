<?php

namespace App\BusinessModules\Features\Notifications\Services;

use App\BusinessModules\Features\Notifications\Models\NotificationTemplate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class TemplateRenderer
{
    public function getTemplate(string $type, string $channel, ?int $organizationId = null): ?NotificationTemplate
    {
        $cacheKey = "notification_template:{$type}:{$channel}:{$organizationId}";

        if (config('notifications.templates.cache_enabled')) {
            return Cache::remember(
                $cacheKey,
                config('notifications.templates.cache_ttl', 3600),
                fn() => $this->fetchTemplate($type, $channel, $organizationId)
            );
        }

        return $this->fetchTemplate($type, $channel, $organizationId);
    }

    protected function fetchTemplate(string $type, string $channel, ?int $organizationId): ?NotificationTemplate
    {
        if ($organizationId) {
            $template = NotificationTemplate::active()
                ->byType($type)
                ->byChannel($channel)
                ->forOrganization($organizationId)
                ->first();

            if ($template) {
                return $template;
            }
        }

        return NotificationTemplate::active()
            ->byType($type)
            ->byChannel($channel)
            ->default()
            ->forOrganization(null)
            ->first();
    }

    public function render(NotificationTemplate $template, array $data): string
    {
        $content = $template->content;

        return $this->renderString($content, $data);
    }

    public function renderString(string $content, array $data): string
    {
        $flatData = $this->flattenData($data);

        return preg_replace_callback(
            '/\{\{([^}]+)\}\}/',
            function ($matches) use ($flatData) {
                $key = trim($matches[1]);
                return $flatData[$key] ?? $matches[0];
            },
            $content
        );
    }

    protected function flattenData(array $data, string $prefix = ''): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            $newKey = $prefix ? "{$prefix}.{$key}" : $key;

            if (is_array($value)) {
                $result = array_merge($result, $this->flattenData($value, $newKey));
            } elseif (is_object($value)) {
                if (method_exists($value, 'toArray')) {
                    $result = array_merge($result, $this->flattenData($value->toArray(), $newKey));
                } else {
                    $result = array_merge($result, $this->flattenData((array) $value, $newKey));
                }
            } else {
                $result[$newKey] = $value;
            }
        }

        return $result;
    }

    public function preview(NotificationTemplate $template, array $sampleData = []): string
    {
        $defaultSampleData = $this->getDefaultSampleData();
        $mergedData = array_merge($defaultSampleData, $sampleData);

        return $this->render($template, $mergedData);
    }

    protected function getDefaultSampleData(): array
    {
        return [
            'user' => [
                'id' => 1,
                'name' => 'Иван Иванов',
                'email' => 'ivan@example.com',
                'phone' => '+7 (999) 123-45-67',
            ],
            'organization' => [
                'id' => 1,
                'name' => 'ООО "Пример"',
            ],
            'project' => [
                'id' => 1,
                'name' => 'Пример проекта',
                'number' => 'PRJ-001',
            ],
            'contract' => [
                'id' => 1,
                'number' => 'CTR-001',
                'total_amount' => '1 000 000',
                'completion_percentage' => '75',
                'url' => url('/contracts/1'),
            ],
            'system' => [
                'app_name' => config('app.name'),
                'app_url' => config('app.url'),
                'support_email' => config('mail.from.address'),
            ],
        ];
    }

    public function clearCache(?int $organizationId = null): void
    {
        if ($organizationId) {
            Cache::forget("notification_template:*:*:{$organizationId}");
        } else {
            Cache::flush();
        }
    }
}

