<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

final class AssistantRequestProgress
{
    public const MAX_EVENTS = 24;

    private const CODES = [
        'rag_search', 'estimates', 'warehouse', 'projects', 'contracts', 'procurement',
        'schedule', 'work_volumes', 'materials', 'reports', 'financial_data',
    ];

    public static function sanitize(mixed $events): array
    {
        if (!is_array($events)) {
            return [];
        }
        $safe = [];
        $previousId = 0;
        foreach ($events as $event) {
            if (!is_array($event) || !is_int($event['id'] ?? null) || $event['id'] <= $previousId
                || !in_array($event['code'] ?? null, self::CODES, true)
                || !in_array($event['state'] ?? null, ['started', 'completed'], true)) {
                continue;
            }
            $previousId = $event['id'];
            $safe[] = ['id' => $event['id'], 'code' => $event['code'], 'state' => $event['state']];
        }
        return array_slice($safe, -self::MAX_EVENTS);
    }

    public static function append(mixed $events, string $code, string $state): array
    {
        $safe = self::sanitize($events);
        if (!in_array($code, self::CODES, true) || !in_array($state, ['started', 'completed'], true)) {
            throw new \InvalidArgumentException('Invalid assistant progress event');
        }
        $last = $safe === [] ? null : $safe[array_key_last($safe)];
        if ($last !== null && $last['code'] === $code && $last['state'] === $state) {
            return $safe;
        }
        $safe[] = ['id' => ($last['id'] ?? 0) + 1, 'code' => $code, 'state' => $state];
        return array_slice($safe, -self::MAX_EVENTS);
    }

    public static function toolCode(string $toolName, array $arguments = []): ?string
    {
        if (in_array($toolName, ['assistant_domain_read', 'assistant_domain_search'], true)) {
            return match ($arguments['domain'] ?? null) {
                'estimates', 'warehouse', 'projects', 'contracts', 'procurement', 'schedule', 'materials', 'reports' => $arguments['domain'],
                'finance' => 'financial_data',
                'works' => 'work_volumes',
                default => null,
            };
        }
        return match ($toolName) {
            'resolve_estimate', 'get_estimate_financial_snapshot', 'get_estimate_positions' => 'estimates',
            'search_warehouse' => 'warehouse',
            'search_projects', 'get_project_snapshot' => 'projects',
            'get_contract_snapshot' => 'contracts',
            'get_procurement_snapshot' => 'procurement',
            'get_schedule_snapshot' => 'schedule',
            'search_materials' => 'materials',
            'get_published_report_financial_evidence' => 'reports',
            'get_live_project_financial_evidence' => 'financial_data',
            default => null,
        };
    }

    public static function toolCompleted(mixed $result): bool
    {
        if (!is_array($result) || $result === [] || !empty($result['error'])
            || ($result['success'] ?? true) === false || ($result['useful'] ?? true) === false) {
            return false;
        }
        if (isset($result['status'])) {
            return in_array($result['status'], ['success', 'resolved', 'completed'], true);
        }
        return is_array($result['results'] ?? null) || !empty($result['source_refs']) || !empty($result['financial_evidence']);
    }
}
