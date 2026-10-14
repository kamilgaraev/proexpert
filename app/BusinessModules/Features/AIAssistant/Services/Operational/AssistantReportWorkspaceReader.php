<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportHttpAuthorizationTargetResolver;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportScopeAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Workspace\GetReportWorkspaceAction;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportCatalogMetadataRegistry;
use App\Models\User;
use App\Services\Entitlements\OrganizationEntitlementService;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class AssistantReportWorkspaceReader extends AssistantOrganizationOperationalReader
{
    public const TYPE = 'live_personal_report_workspace_projection';

    public static function hasIntent(string $query): bool
    {
        return preg_match('/отч[её]т/iu', $query) === 1 && preg_match('/избран|открывал|недавн|рабоч[а-яё]*\s+пространств/iu', $query) === 1;
    }

    public static function matches(string $query): bool
    {
        return self::hasIntent($query) && preg_match('/добавь|удали|измени|сохрани|создай|сформируй|скачай|запусти|\bправ[а-яё]*|подписк|рассылк|регулярн|сотрудник|готов[а-яё]*\s+выгруз|каталог/iu', $query) !== 1;
    }

    protected function collect(User $actor, int $organizationId): array
    {
        if (! $this->access->canCurrentPermission($actor, $organizationId, 'reports.view')
            || ! app(OrganizationEntitlementService::class)->hasModuleAccess($organizationId, 'reports')) { throw new AuthorizationException; }
        $auth = app(CurrentReportScopeAuthorizer::class)->authorizeCatalog((int) $actor->id, $organizationId,
            new \DateTimeZone((string) config('app.timezone', 'UTC')), app(ReportHttpAuthorizationTargetResolver::class)->catalog());
        $workspace = app(GetReportWorkspaceAction::class)->handle($auth->context);
        $metadata = app(ReportCatalogMetadataRegistry::class);
        $rows = static fn (array $codes): array => array_map(static fn (string $code): array => ['code' => $code, 'title' => trans_message($metadata->published($code)->titleKey)], $codes);
        return ['favourites' => $rows($workspace->favouriteReportCodes), 'recent' => $rows($workspace->recentReportCodes),
            'display' => ['catalog_group_order' => $workspace->display->catalogGroupOrder, 'collapsed_catalog_groups' => $workspace->display->collapsedCatalogGroups,
                'landing_section' => $workspace->display->landingSection]];
    }

    protected function format(array $data): string
    {
        $lines = [trans_message('ai_assistant_operational.report_workspace_scope', ['date' => $data['as_of_date']])];
        foreach (['favourites', 'recent'] as $section) {
            $lines[] = trans_message('ai_assistant_operational.report_workspace_'.$section, ['count' => count($data[$section])]);
            foreach ($data[$section] as $row) { $lines[] = '- '.self::text($row['title']); }
        }
        $lines[] = trans_message('ai_assistant_operational.report_workspace_basis');
        return implode("\n", $lines);
    }
}
