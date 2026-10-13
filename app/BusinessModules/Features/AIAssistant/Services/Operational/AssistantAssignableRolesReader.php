<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

use App\Models\User;
use App\Services\User\AdminUserRolePolicy;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class AssistantAssignableRolesReader extends AssistantOrganizationOperationalReader
{
    public const TYPE = 'live_assignable_user_roles_projection';

    public static function hasIntent(string $query): bool
    {
        return preg_match('/рол[ьи][а-яё]*.*(?:назнач|выдать)|(?:назнач|выдать).*рол[ьи]|могу.*назначить\s+администратор/iu', $query) === 1;
    }

    public static function matches(string $query): bool
    {
        return self::hasIntent($query) && preg_match('/я\s+.*могу|могу\s+ли|мне\s+доступ|какие\s+роли/iu', $query) === 1
            && preg_match('/назначь|создай|измени|удали|выдай|сними|отзови|сч[её]т|договор|проект|отпуск|зарплат|пользовательск[а-яё]*\s+рол|кастом/iu', $query) !== 1;
    }

    protected function collect(User $actor, int $organizationId): array
    {
        if (! $this->access->canCurrentPermission($actor, $organizationId, 'admin.users.view')) { throw new AuthorizationException; }
        $roles = app(AdminUserRolePolicy::class)->optionsFor($actor, $organizationId);

        return ['roles' => $roles, 'can_assign_organization_admin' => in_array('organization_admin', array_column($roles, 'slug'), true),
            'can_edit_users' => $this->access->canCurrentPermission($actor, $organizationId, 'admin.users.edit')];
    }

    protected function format(array $data): string
    {
        $lines = [trans_message('ai_assistant_operational.roles_heading')];
        foreach ($data['roles'] as $role) { $lines[] = '- '.self::text($role['name']); }
        if ($data['roles'] === []) { $lines[] = trans_message('ai_assistant_operational.roles_empty'); }
        $lines[] = trans_message('ai_assistant_operational.roles_admin_'.($data['can_assign_organization_admin'] ? 'yes' : 'no'));
        $lines[] = trans_message('ai_assistant_operational.roles_edit_'.($data['can_edit_users'] ? 'yes' : 'no'));
        $lines[] = trans_message('ai_assistant_operational.roles_basis');

        return implode("\n", $lines);
    }
}
