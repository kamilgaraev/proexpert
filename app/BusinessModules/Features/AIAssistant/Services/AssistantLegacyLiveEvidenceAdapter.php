<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class AssistantLegacyLiveEvidenceAdapter
{
    public function __construct(
        private readonly AssistantDomainReadService $reader,
        private readonly AssistantDomainCatalog $catalog,
    ) {}

    public function read(string $toolName, array $result, User $actor, int $organizationId): array
    {
        if (($result['status'] ?? null) !== 'success') {
            return [];
        }
        $groups = match ($toolName) {
            'get_project_snapshot' => [['projects', 'project', $result['results'] ?? [], 'project']],
            'get_contract_snapshot' => [['contracts', 'contract', $result['contracts'] ?? [], 'contract']],
            'get_schedule_snapshot' => [['schedule', 'schedule_task', $result['tasks'] ?? [], null]],
            'get_procurement_snapshot' => [
                ['procurement', 'purchase_request', $result['purchase_requests'] ?? [], null],
                ['procurement', 'purchase_order', $result['purchase_orders'] ?? [], null],
            ],
            'search_projects' => [['projects', 'project', $result['results'] ?? [], null]],
            'search_users' => [['people', 'user', $result['results'] ?? [], null]],
            default => [],
        };
        $seen = [];
        $evidence = [];
        foreach ($groups as [$domain, $type, $items, $nested]) {
            if (! is_array($items)) {
                continue;
            }
            foreach (array_slice($items, 0, 30) as $item) {
                $record = is_array($item) ? ($nested === null ? $item : ($item[$nested] ?? null)) : null;
                if (! is_array($record) || ! is_int($record['id'] ?? null) || $record['id'] < 1) {
                    continue;
                }
                $key = $type.':'.$record['id'];
                if (isset($seen[$key])) {
                    continue;
                }
                if (count($seen) >= AssistantStructuredFactFormatter::MAX_ROWS) {
                    return $evidence;
                }
                $seen[$key] = true;
                $returnedFields = array_keys($record);
                if (in_array($type, ['purchase_request', 'purchase_order'], true) && isset($record['number'])) {
                    $returnedFields[] = $type === 'purchase_request' ? 'request_number' : 'order_number';
                }
                if ($type === 'purchase_request' && isset($record['currency'])) {
                    $returnedFields[] = 'budget_currency';
                }
                $fields = array_values(array_intersect($returnedFields, $this->catalog->definition($domain)?->fields ?? []));
                if ($fields === []) {
                    continue;
                }
                try {
                    $read = $this->reader->execute('read', ['domain' => $domain, 'entity_type' => $type,
                        'id' => $record['id'], 'fields' => $fields], $actor, $organizationId);
                } catch (AccessDeniedHttpException|ValidationException) {
                    continue;
                }
                if (isset($read['structured_fact_evidence'], $read['server_formatted_facts'])) {
                    $evidence[] = $read;
                }
            }
        }

        return $evidence;
    }
}
