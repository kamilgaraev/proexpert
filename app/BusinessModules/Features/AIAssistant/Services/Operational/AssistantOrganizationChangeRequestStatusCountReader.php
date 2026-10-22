<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantOrganizationChangeRequestStatusCountIntent;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestExecutionContext;
use App\BusinessModules\Features\ChangeManagement\Services\ChangeRequestStatusCountReadService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class AssistantOrganizationChangeRequestStatusCountReader extends AssistantOrganizationOperationalReader
{
    public const TYPE = 'live_organization_change_request_status_counts_projection';

    private const GROUP_LIMIT = 100;

    public function __construct(AssistantDataAccessPolicy $access, private ChangeRequestStatusCountReadService $changes)
    {
        parent::__construct($access);
    }

    public static function hasIntent(string $query): bool
    {
        return AssistantOrganizationChangeRequestStatusCountIntent::hasIntent($query);
    }

    public static function matches(string $query): bool
    {
        return AssistantOrganizationChangeRequestStatusCountIntent::matches($query);
    }

    public function read(User $actor, int $organizationId, array $arguments): array
    {
        $read = fn (): array => parent::read($actor, $organizationId, $arguments);

        return app()->bound(AssistantRequestExecutionContext::class)
            ? app(AssistantRequestExecutionContext::class)->withOperationBudget($read, 30_000, 'pgsql') : $read();
    }

    protected function collect(User $actor, int $organizationId): array
    {
        $query = $this->access->entityQuery($actor, $organizationId, 'change_request');
        if ($query === null) {
            throw new AuthorizationException;
        }
        $records = $this->changes->query($actor, $organizationId, $query)
            ->orderBy('change_management_change_requests.status')->limit(self::GROUP_LIMIT)->toBase()->get();
        $rows = $records->map(static fn (object $row): array => [
            'status' => (string) $row->status, 'count' => (int) $row->request_count,
        ])->all();
        $groups = (int) ($records->first()->group_count ?? 0);

        return ['total_count' => (int) ($records->first()->total_count ?? 0),
            'group_count' => $groups, 'shown_group_count' => count($rows), 'truncated' => $groups > count($rows), 'rows' => $rows];
    }

    protected function format(array $data): string
    {
        $lines = [trans_message('ai_assistant_change_request_counts.scope', ['date' => $data['as_of_date']]),
            trans_message('ai_assistant_change_request_counts.criteria'),
            trans_message('ai_assistant_change_request_counts.total', ['total' => $data['total_count']])];
        if ($data['rows'] === []) {
            $lines[] = trans_message('ai_assistant_change_request_counts.empty');
        } else {
            $table = [trans_message('ai_assistant_change_request_counts.columns'), '| --- | --- |'];
            foreach ($data['rows'] as $row) {
                $status = in_array($row['status'], ['draft', 'submitted', 'impact_assessment', 'internal_review', 'customer_review',
                    'approved', 'implemented', 'closed', 'rejected', 'cancelled'], true)
                    ? trans_message('ai_assistant_change_request_counts.status_'.$row['status']) : $row['status'];
                $table[] = '| '.str_replace('|', '\\|', self::text($status)).' | '.$row['count'].' |';
            }
            $lines[] = implode("\n", $table);
        }
        if ($data['truncated']) {
            $lines[] = trans_message('ai_assistant_change_request_counts.limit', ['shown' => $data['shown_group_count'], 'total' => $data['group_count']]);
        }

        return implode("\n\n", $lines);
    }
}
