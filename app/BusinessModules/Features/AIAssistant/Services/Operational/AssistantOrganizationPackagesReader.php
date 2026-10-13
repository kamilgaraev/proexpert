<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

use App\Models\User;
use App\Services\Landing\PackageService;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class AssistantOrganizationPackagesReader extends AssistantOrganizationOperationalReader
{
    public const TYPE = 'live_organization_active_packages_projection';

    public static function hasIntent(string $query): bool
    {
        return preg_match('/пакет[а-яё]*.*(?:мост|организац)|подписк[а-яё]*.*(?:организац|доступ)|оплачен[а-яё]*\s+доступ/iu', $query) === 1;
    }

    public static function matches(string $query): bool
    {
        return self::hasIntent($query) && preg_match('/действуют|действующ|сейчас|текущ|до\s+каких|срок|когда/iu', $query) === 1
            && preg_match('/купи|подключи|продли|оплати|отключи|измени|создай|расход|затрат|потрач|прибыл|выручк|рентабел|марж|сч[её]т|проект|договор|модул|баланс|единиц|сотрудник|зарплат/iu', $query) !== 1;
    }

    protected function collect(User $actor, int $organizationId): array
    {
        if (! $this->access->canCurrentPermission($actor, $organizationId, 'billing.view')) { throw new AuthorizationException; }
        $rows = [];
        foreach (app(PackageService::class)->getAllPackages($organizationId) as $package) {
            if ($package['is_active'] !== true) { continue; }
            $rows[] = array_intersect_key($package, array_flip(['slug', 'name', 'status', 'access_source', 'current_period_start_at', 'current_period_end_at', 'trial_ends_at']));
        }

        return ['packages' => $rows, 'total' => count($rows)];
    }

    protected function format(array $data): string
    {
        $lines = [trans_message('ai_assistant_operational.packages_heading', ['count' => $data['total']])];
        foreach ($data['packages'] as $row) {
            $lines[] = '- '.self::text($row['name']).'; '.trans_message('ai_assistant_operational.package_source_'.$row['access_source']).'; '
                .trans_message('ai_assistant_operational.package_status_'.$row['status']).'; '
                .trans_message('ai_assistant_operational.package_period', ['from' => $row['current_period_start_at'] ?? trans_message('ai_assistant_operational.not_recorded'),
                    'to' => $row['current_period_end_at'] ?? trans_message('ai_assistant_operational.not_recorded')]);
            if ($row['trial_ends_at'] !== null) { $lines[] = trans_message('ai_assistant_operational.package_trial_end', ['date' => $row['trial_ends_at']]); }
        }
        $lines[] = trans_message('ai_assistant_operational.packages_basis');

        return implode("\n", $lines);
    }
}
