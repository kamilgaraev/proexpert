<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

use App\Enums\UserProjectAccessMode;
use App\Models\Project;
use App\Models\User;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

final readonly class AssistantOrganizationProjectsReader extends AssistantOrganizationOperationalReader
{
    public const TYPE = 'live_organization_project_roster_projection';

    public static function matches(string $query): bool
    {
        return self::hasIntent($query)
            && preg_match('/создай|удали|архивируй|измени|бюджет|оплат|договор|смет|акт(?:ы|ов)\b|сотрудник|тендер|заявк|выручк|прибыл|расход/iu', $query) !== 1;
    }

    public static function hasIntent(string $query): bool
    {
        return preg_match('/проект|объект/iu', $query) === 1 && preg_match('/активн|архивн|действующ/iu', $query) === 1
            && \App\BusinessModules\Features\AIAssistant\Services\AssistantRequestProjectScope::organizationWide($query);
    }

    protected function collect(User $actor, int $organizationId): array
    {
        if (! $this->access->canReadDomain($actor, $organizationId, 'projects')
            || ! $this->access->canCurrentPermission($actor, $organizationId, 'admin.projects.view')) { throw new AuthorizationException; }
        $query = Project::query()->accessibleByOrganization($organizationId);
        $mode = app(UserProjectAccessService::class)->modeFor($actor, $organizationId);
        if ($mode !== UserProjectAccessMode::ALL_PROJECTS) {
            $query->whereHas('users', static fn (Builder $q) => $q->where('users.id', $actor->id)->where('project_user.is_active', true));
        }
        $active = (clone $query)->where('is_archived', false)->where('status', 'active');
        $examples = (clone $active)->orderByRaw('end_date IS NULL')->orderBy('end_date')->orderBy('id')->limit(20)
            ->select(['id', 'name', 'end_date'])->get()->map(static fn ($row): array => ['id' => (int) $row->id, 'name' => $row->name, 'end_date' => $row->end_date?->toDateString()])->all();
        return ['scope' => $mode->value, 'total' => (clone $query)->count(), 'active' => (clone $active)->count(),
            'archived' => (clone $query)->where('is_archived', true)->count(), 'examples' => $examples];
    }

    protected function format(array $data): string
    {
        $lines = [trans_message('ai_assistant_operational.portfolio_scope', ['date' => $data['as_of_date']]),
            trans_message('ai_assistant_operational.portfolio_counts', ['total' => $data['total'], 'active' => $data['active'], 'archived' => $data['archived']])];
        foreach ($data['examples'] as $row) {
            $lines[] = '- #'.$row['id'].' '.self::text($row['name']).'; '.trans_message('ai_assistant_operational.portfolio_date', [
                'date' => $row['end_date'] ?? trans_message('ai_assistant_operational.not_recorded')]);
        }
        $lines[] = trans_message('ai_assistant_operational.portfolio_basis');
        $lines[] = trans_message('ai_assistant_operational.examples_basis');
        return implode("\n", $lines);
    }
}
