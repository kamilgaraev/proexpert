<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

use App\Models\User;

final readonly class AssistantOwnAdminPermissionsReader extends AssistantOrganizationOperationalReader
{
    public const TYPE = 'live_personal_admin_permissions_projection';

    private const PERMISSIONS = [
        'users' => ['view' => 'admin.users.view', 'create' => 'admin.users.create', 'edit' => 'admin.users.edit', 'block' => 'admin.users.block'],
        'contracts' => ['view' => 'contracts.view', 'create' => 'contracts.create', 'edit' => 'contracts.edit', 'delete' => 'contracts.delete'],
        'invoices' => ['view' => 'payments.invoice.view', 'view_all' => 'payments.invoice.view_all', 'create' => 'payments.invoice.create', 'edit' => 'payments.invoice.edit', 'delete' => 'payments.invoice.delete'],
        'estimates' => ['view' => 'estimates.view', 'create' => 'estimates.create', 'edit' => 'estimates.edit', 'export' => 'estimates.export'],
        'warehouse' => ['view' => 'warehouse.view', 'manage_stock' => 'warehouse.manage_stock', 'export' => 'warehouse.reports.export'],
        'reports' => ['view' => 'reports.view', 'run' => 'reports.run', 'export' => 'reports.export', 'download' => 'reports.download'],
        'organization' => ['view' => 'organization.manage', 'edit' => 'organization.manage'],
        'notifications' => ['view' => 'admin.access'],
    ];

    public static function hasIntent(string $query): bool
    {
        return preg_match('/(?:у\s+меня|мои|мо[её]|\bя\s+|могу\s+ли\s+я).*\bправ|\bправ[а-яё]*.*(?:у\s+меня|мне)|могу\s+ли\s+я\s+управлять|\bчто\s+я\s+могу\s+(?:делать|выполнять)\s+с\s+(?:пользовател|договор)/iu', $query) === 1;
    }

    public static function matches(string $query): bool
    {
        return self::hasIntent($query) && preg_match('/админк|пользовател|уч[её]тн|договор|сч[её]т|смет|склад|отч[её]т|настройк|организаци|уведомлен/iu', $query) === 1
            && preg_match('/измени|назначь|выдай|отзови|удали|создай|оплати|заблокируй|остатк|просроч|сколько|сумм|расход|затрат|потрач|прибыл|выручк|рентабел|марж|сотрудник|отпуск|проект|заказ|материал|бюджет|непрочитан|сообщен|очеред|готов|статус|ошибк|последн/iu', $query) !== 1;
    }

    protected function collect(User $actor, int $organizationId): array
    {
        $result = [];
        foreach (self::PERMISSIONS as $section => $permissions) {
            foreach ($permissions as $action => $permission) {
                $result[$section][$action] = $this->access->canCurrentPermission($actor, $organizationId, $permission);
            }
        }
        if (! User::query()->whereKey($actor->id)->whereNotNull('email_verified_at')->exists()) {
            $result['organization'] = ['view' => false, 'edit' => false];
        }
        $result['notifications']['manage_preferences'] = $actor->activeOrganizations()->whereKey($organizationId)->exists();

        return ['permissions' => $result];
    }

    protected function format(array $data): string
    {
        $lines = [trans_message('ai_assistant_operational.own_permissions_heading')];
        foreach ($data['permissions'] as $section => $permissions) {
            $actions = [];
            foreach ($permissions as $action => $allowed) {
                $actions[] = trans_message('ai_assistant_operational.permission_action_'.$action).': '.trans_message('ai_assistant_operational.permission_'.($allowed ? 'yes' : 'no'));
            }
            $lines[] = '- '.trans_message('ai_assistant_operational.permission_section_'.$section).': '.implode('; ', $actions).'.';
        }
        $lines[] = trans_message('ai_assistant_operational.own_permissions_basis');
        $lines[] = trans_message('ai_assistant_operational.own_permissions_report_basis');

        return implode("\n", $lines);
    }
}
