<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Domain\Authorization\Services\RolePayloadFormatter;
use App\Domain\Authorization\Services\RoleScanner;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class AssistantOwnRolesReader extends AssistantOrganizationOperationalReader
{
    public const TYPE = 'live_personal_organization_roles_projection';

    public static function hasIntent(string $query): bool
    {
        return preg_match('/\b(?:у\s+меня|моя|мои|моей|я)\b.*\b(?:роль|роли)\b|\b(?:роль|роли)\b.*\bу\s+меня\b/iu', $query) === 1;
    }

    public static function matches(string $query): bool
    {
        return self::hasIntent($query) && preg_match('/назначь|измени|выдай|отзови|создай|удали|могу.*(?:назнач|выдать)|сотрудник|пользовател|проект|\bправ[а-яё]*|сч[её]т|выгруз|пакет|уведомлен/iu', $query) !== 1;
    }

    protected function collect(User $actor, int $organizationId): array
    {
        $context = AuthorizationContext::findOrganizationContext($organizationId);
        if ($context === null) { throw new AuthorizationException; }
        $roles = app(AuthorizationService::class)->forCurrentChecks()->getUserRoles($actor, $context);
        $scanner = app(RoleScanner::class); $formatter = app(RolePayloadFormatter::class);
        $items = [];
        foreach ($roles as $role) {
            $definition = $role->role_type === 'system' ? $scanner->getRole($role->role_slug) : null;
            $items[] = ['slug' => $role->role_slug, 'type' => $role->role_type, 'context_id' => $role->context_id,
                'scope' => $role->context_id === $context->id ? 'organization' : 'global',
                'name' => $role->role_type === 'custom' ? ($role->customRole?->name ?? trans_message('ai_assistant_operational.own_role_unknown'))
                    : ($definition === null ? trans_message('ai_assistant_operational.own_role_unknown') : $formatter->translatedRoleName($role->role_slug, $definition)),
                'role_active' => $role->role_type === 'custom' ? (bool) $role->customRole?->is_active : $definition !== null,
                'expires_at' => $role->expires_at?->toIso8601String()];
        }
        usort($items, static fn (array $a, array $b): int => strcmp($a['slug'], $b['slug']) ?: (($a['context_id'] ?? 0) <=> ($b['context_id'] ?? 0)));
        return ['roles' => $items];
    }

    protected function format(array $data): string
    {
        $lines = [trans_message('ai_assistant_operational.own_roles_scope', ['date' => $data['as_of_date'], 'count' => count($data['roles'])])];
        foreach ($data['roles'] as $role) {
            $lines[] = '- '.self::text($role['name']).'; '.trans_message('ai_assistant_operational.own_role_scope_'.$role['scope'])
                .'; '.trans_message('ai_assistant_operational.own_role_'.($role['role_active'] ? 'active' : 'inactive'))
                .'; '.($role['expires_at'] === null ? trans_message('ai_assistant_operational.own_role_no_expiry') : trans_message('ai_assistant_operational.own_role_expiry', ['date' => self::text($role['expires_at'])]));
        }
        $lines[] = trans_message('ai_assistant_operational.own_roles_basis');
        return implode("\n", $lines);
    }
}
