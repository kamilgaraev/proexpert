<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportHttpAuthorizationTargetResolver;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportScopeAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportCatalogAction;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogDefinitionView;
use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantMutationIntent;
use App\Models\User;

final readonly class AssistantReportSubscriptionCatalogReader extends AssistantOrganizationOperationalReader
{
    public const TYPE = 'live_report_subscription_catalog_projection';

    public static function hasIntent(string $query): bool
    {
        return preg_match('/отч[её]т/iu', $query) === 1
            && preg_match('/регулярн|автоматическ|расписан|подписк|рассылк|кажд[а-яё]*\s+(?:недел|месяц|день)|еженедел|ежемесяч|ежеднев/iu', $query) === 1
            && preg_match('/поддерж|доступ|возможн|на\s+какие\s+отч[её]т|(?:можно|могу)[^.!?]*(?:настро|подпис|включ|созда|получ|присыл|выгру|рассыл)/iu', $query) === 1;
    }

    public static function intentQuery(string $query): string
    {
        return preg_replace('/(отч[её]т[а-яё]*\s+)(?:об?|по)\s+(?:оплат[а-яё]*|плат[её]ж[а-яё]*|сч[её]т[а-яё]*|расход[а-яё]*|затрат[а-яё]*)(?!\pL)/iu', '$1', $query) ?? $query;
    }

    public static function matches(string $query): bool
    {
        $query = preg_replace('/а\s+не\s+мои\s+существующ[а-яё]*\s+расписан[а-яё]*/iu', '', $query) ?? $query;

        return self::hasIntent($query) && ! AssistantMutationIntent::containsRequest($query)
            && ! AssistantMutationIntent::containsFollowUpCommand($query)
            && preg_match('/пакет|тариф|биллинг|оплат|плат[её]ж|сч[её]т|расход|затрат|долг|задолж|аванс|бюджет|прибыл|выручк|марж|рентабел|зарплат|сумм|продлен|стоим|сколько\s+стоит|цен[аы]|баланс|единиц/iu', self::intentQuery($query)) !== 1
            && preg_match('/(?<!\pL)(?:мои|моих)\s+(?:автоматическ[а-яё]*\s+)?(?:подписк|расписан)/iu', $query) !== 1
            && preg_match('/(?:уже|мои|у\s+меня|у\s+нас)[^.!?]*(?:настроен|активн|приостановл|отключен)|следующ[а-яё]*\s+запуск/iu', $query) !== 1;
    }

    protected function collect(User $actor, int $organizationId): array
    {
        $auth = app(CurrentReportScopeAuthorizer::class)->authorizeCatalog((int) $actor->id, $organizationId,
            new \DateTimeZone((string) config('app.timezone', 'UTC')), app(ReportHttpAuthorizationTargetResolver::class)->catalog());
        $catalog = app(GetReportCatalogAction::class)->handle($auth->context, $auth);
        $supported = array_values(array_filter($catalog->definitions, static fn (ReportCatalogDefinitionView $definition): bool =>
            $definition->scheduling->supportsSubscriptions && $definition->scheduling->reproducibleScheduledSnapshot));
        $available = array_values(array_filter($supported, static fn (ReportCatalogDefinitionView $definition): bool =>
            $definition->visibility->canManage && $definition->visibility->canRun && $definition->visibility->canExport && $definition->formats !== []));

        return ['manifest' => $catalog->manifestSha256->value, 'catalog_total' => count($catalog->definitions),
            'supported_total' => count($supported), 'available_total' => count($available),
            'items' => array_map(static fn (ReportCatalogDefinitionView $definition): array => [
                'code' => $definition->code, 'title' => trans_message($definition->titleKey), 'formats' => $definition->formats,
            ], array_slice($available, 0, 20))];
    }

    protected function format(array $data): string
    {
        $lines = [trans_message('ai_assistant_operational.subscription_catalog_counts', [
            'date' => $data['as_of_date'], 'catalog' => $data['catalog_total'], 'supported' => $data['supported_total'], 'available' => $data['available_total'],
        ])];
        foreach ($data['items'] as $item) {
            $lines[] = '- '.self::text($item['title']).'; '.implode(', ', array_map(static fn (string $format): string => strtoupper(self::text($format)), $item['formats']));
        }
        if ($data['available_total'] === 0) {
            $lines[] = trans_message('ai_assistant_operational.subscription_catalog_empty');
        }
        $lines[] = trans_message('ai_assistant_operational.subscription_catalog_basis', ['shown' => count($data['items'])]);

        return implode("\n", $lines);
    }
}
