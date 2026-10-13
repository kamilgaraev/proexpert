<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

use App\Models\Module;
use App\Models\User;
use App\Services\Entitlements\OrganizationEntitlementService;

final readonly class AssistantOrganizationModulesReader extends AssistantOrganizationOperationalReader
{
    public const TYPE = 'live_organization_effective_modules_projection';

    public static function hasIntent(string $query): bool
    {
        return preg_match('/модул[а-яё]*.*(?:включ|подключ|доступ|организац)|(?:включ|подключ)[а-яё]*.*модул/iu', $query) === 1;
    }

    public static function matches(string $query): bool
    {
        return self::hasIntent($query) && preg_match('/какие|список|покажи|сколько/iu', $query) === 1
            && preg_match('/подключи|отключи|купи|оплати|измени|создай|проект|договор|сч[её]т|пакет|баланс|единиц|сотрудник|зарплат/iu', $query) !== 1;
    }

    protected function collect(User $actor, int $organizationId): array
    {
        $slugs = app(OrganizationEntitlementService::class)->getEffectiveModuleSlugs($organizationId);
        $rows = Module::query()->whereIn('slug', $slugs)->where('is_active', true)->orderBy('display_order')->orderBy('id')
            ->get(['slug', 'name'])->map(static fn (Module $module): array => ['slug' => $module->slug, 'name' => $module->name])->all();

        return ['total' => count($rows), 'modules' => $rows];
    }

    protected function format(array $data): string
    {
        $lines = [trans_message('ai_assistant_operational.modules_heading', ['count' => $data['total']])];
        foreach ($data['modules'] as $row) { $lines[] = '- '.self::text($row['name']); }
        $lines[] = trans_message('ai_assistant_operational.modules_basis');

        return implode("\n", $lines);
    }
}
