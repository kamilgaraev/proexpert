<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantOrganizationHandoverControlIntent;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestExecutionContext;
use App\BusinessModules\Features\HandoverAcceptance\Services\HandoverAcceptanceControlReadService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class AssistantOrganizationHandoverControlReader extends AssistantOrganizationOperationalReader
{
    public const TYPE = 'live_organization_handover_control_projection';

    private const EXAMPLE_LIMIT = 50;

    public function __construct(AssistantDataAccessPolicy $access, private HandoverAcceptanceControlReadService $handover)
    {
        parent::__construct($access);
    }

    public static function hasIntent(string $query): bool
    {
        return AssistantOrganizationHandoverControlIntent::hasIntent($query);
    }

    public static function matches(string $query): bool
    {
        return AssistantOrganizationHandoverControlIntent::matches($query);
    }

    public function read(User $actor, int $organizationId, array $arguments): array
    {
        $read = fn (): array => parent::read($actor, $organizationId, $arguments);

        return app()->bound(AssistantRequestExecutionContext::class)
            ? app(AssistantRequestExecutionContext::class)->withOperationBudget($read, 30_000, 'pgsql') : $read();
    }

    protected function collect(User $actor, int $organizationId): array
    {
        $query = $this->access->entityQuery($actor, $organizationId, 'acceptance_scope');
        if ($query === null) {
            throw new AuthorizationException;
        }
        $records = $this->handover->query($actor, $organizationId, $query)
            ->orderBy('acceptance_scopes.id')->limit(self::EXAMPLE_LIMIT)->toBase()->get();
        $total = (int) ($records->first()->matched_count ?? 0);
        $rows = $records->map(static fn (object $row): array => ['id' => (int) $row->id,
            'project_id' => (int) $row->project_id, 'project_name' => $row->project_name === null ? null : (string) $row->project_name,
            'title' => (string) $row->title, 'status' => (string) $row->status,
            'planned_acceptance_date' => $row->planned_acceptance_date === null ? null : (string) $row->planned_acceptance_date,
            'open_findings_count' => (int) $row->open_findings_count])->all();

        return ['total_count' => $total, 'shown_count' => count($rows), 'truncated' => $total > count($rows), 'rows' => $rows];
    }

    protected function format(array $data): string
    {
        $lines = [trans_message('ai_assistant_handover_control.scope', ['date' => $data['as_of_date']]),
            trans_message('ai_assistant_handover_control.criteria'),
            trans_message('ai_assistant_handover_control.total', ['total' => $data['total_count']])];
        if ($data['rows'] === []) {
            $lines[] = trans_message('ai_assistant_handover_control.empty');
        } else {
            $table = [trans_message('ai_assistant_handover_control.columns'), '| --- | --- | --- | --- | --- | --- |'];
            foreach ($data['rows'] as $row) {
                $statusKey = in_array($row['status'], ['planned', 'in_progress', 'findings_open', 'ready_for_reinspection', 'reopened', 'rejected'], true)
                    ? 'status_'.$row['status'] : 'status_unknown';
                $cells = [(string) $row['id'], $row['project_name'] ?? trans_message('ai_assistant_handover_control.project_unavailable'),
                    $row['title'], trans_message('ai_assistant_handover.'.$statusKey),
                    $row['planned_acceptance_date'] ?? trans_message('ai_assistant_handover_control.no_date'), (string) $row['open_findings_count']];
                $table[] = '| '.implode(' | ', array_map(static fn (string $cell): string => str_replace('|', '\\|', self::text($cell)), $cells)).' |';
            }
            $lines[] = implode("\n", $table);
        }
        if ($data['truncated']) {
            $lines[] = trans_message('ai_assistant_handover_control.limit', ['shown' => $data['shown_count'], 'total' => $data['total_count']]);
        }
        $lines[] = trans_message('ai_assistant_handover_control.readiness');

        return implode("\n\n", $lines);
    }
}
