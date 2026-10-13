<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

use App\Enums\UserProjectAccessMode;
use App\Models\Project;
use App\Models\User;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class AssistantOwnProjectAccessReader extends AssistantOrganizationOperationalReader
{
    public const TYPE = 'live_personal_project_access_projection';

    public static function hasIntent(string $query): bool
    {
        return preg_match('/доступ[а-яё]*\s+к\s+проект|режим[а-яё]*\s+доступ/iu', $query) === 1;
    }

    public static function matches(string $query): bool
    {
        return self::hasIntent($query) && preg_match('/у\s+меня|мо[йиеё][а-яё]*|мне/iu', $query) === 1
            && preg_match('/измени|дай\s+доступ|открой\s+доступ|назначь|сотрудник|поставщик|сч[её]т|оплат|бюджет|смет|уведомлен/iu', $query) !== 1;
    }

    protected function collect(User $actor, int $organizationId): array
    {
        if (! $this->access->canCurrentPermission($actor, $organizationId, 'admin.users.view')) {
            throw new AuthorizationException;
        }
        $mode = app(UserProjectAccessService::class)->modeFor($actor, $organizationId);
        $available = Project::query()->accessibleByOrganization($organizationId)->where('is_archived', false);
        $assigned = $actor->assignedProjects()->wherePivot('is_active', true)->whereIn('projects.id', (clone $available)->select('projects.id'));
        $assignedTotal = (clone $assigned)->count();
        $catalogTotal = (clone $available)->count();
        $total = $mode === UserProjectAccessMode::ALL_PROJECTS ? $catalogTotal : $assignedTotal;
        $assignmentExamples = (clone $assigned)->orderBy('projects.name')->limit(20)->select(['projects.id', 'projects.name'])->get()
            ->map(static fn ($row): array => ['id' => (int) $row->id, 'name' => $row->name])->all();
        $general = $mode === UserProjectAccessMode::ALL_PROJECTS ? (clone $available)->whereNotIn('projects.id', (clone $assigned)->select('projects.id')) : null;
        $generalExamples = $general?->orderBy('projects.name')->limit(20)->select(['projects.id', 'projects.name'])->get()
            ->map(static fn ($row): array => ['id' => (int) $row->id, 'name' => $row->name])->all() ?? [];

        return ['mode' => $mode->value, 'total' => $total, 'assigned' => $assignedTotal, 'general' => $total - $assignedTotal,
            'assignment_examples' => $assignmentExamples, 'general_examples' => $generalExamples];
    }

    protected function format(array $data): string
    {
        $lines = [trans_message('ai_assistant_operational.access_mode_'.$data['mode']),
            trans_message('ai_assistant_operational.access_counts', ['total' => $data['total'], 'assigned' => $data['assigned'], 'general' => $data['general']])];
        foreach (['assignment_examples' => 'assigned', 'general_examples' => 'general'] as $key => $type) {
            $lines[] = trans_message('ai_assistant_operational.access_'.$type);
            foreach ($data[$key] as $row) {
                $lines[] = '- #'.$row['id'].' '.self::text($row['name']);
            }
        }
        $lines[] = trans_message('ai_assistant_operational.access_basis');
        $lines[] = trans_message('ai_assistant_operational.examples_basis');

        return implode("\n", $lines);
    }
}
