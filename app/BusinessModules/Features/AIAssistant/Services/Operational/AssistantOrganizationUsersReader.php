<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

use App\Models\User;
use App\Repositories\UserRepository;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class AssistantOrganizationUsersReader extends AssistantOrganizationOperationalReader
{
    public const TYPE = 'live_organization_user_accounts_projection';

    public static function hasIntent(string $query): bool
    {
        return preg_match('/пользовател|уч[её]тн[а-яё]*\s+запис/iu', $query) === 1
            && ! AssistantOwnAdminPermissionsReader::hasIntent($query) && ! AssistantAssignableRolesReader::hasIntent($query);
    }

    public static function matches(string $query): bool
    {
        return self::hasIntent($query) && preg_match('/сколько|количеств|подсчитай/iu', $query) === 1
            && preg_match('/создай|удали|отключи|заблокируй|измени|назначь|роль|роли|прав[а-яё]*|договор|сч[её]т|проект|отпуск|зарплат|телефон[а-яё]*\s+(?:покажи|список)|покажи.*(?:телефон|почт)/iu', $query) !== 1;
    }

    protected function collect(User $actor, int $organizationId): array
    {
        if (! $this->access->canCurrentPermission($actor, $organizationId, 'admin.users.view')) { throw new AuthorizationException; }
        $query = app(UserRepository::class)->queryInOrganization($organizationId);
        $total = (clone $query)->count();
        $active = (clone $query)->where('users.is_active', true)->count();
        $inactive = (clone $query)->where('users.is_active', false)->count();

        return ['total' => $total, 'active' => $active, 'inactive' => $inactive, 'unspecified' => $total - $active - $inactive];
    }

    protected function format(array $data): string
    {
        return trans_message('ai_assistant_operational.user_counts', $data)."\n".trans_message('ai_assistant_operational.user_counts_basis');
    }
}
