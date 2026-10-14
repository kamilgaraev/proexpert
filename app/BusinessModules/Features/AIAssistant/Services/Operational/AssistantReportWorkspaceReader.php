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
        return preg_match('/отч[её]т/iu', $query) === 1 && preg_match('/избран|открывал|недавн|рабоч[а-яё]*\s+пространств|сохран[а-яё]*[\s\S]*представлен|представлен[а-яё]*[\s\S]*сохран/iu', $query) === 1;
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
        $views = []; $unavailable = false;
        $definitions = app(\App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry::class);
        $access = app(\App\BusinessModules\Core\Reporting\Application\Access\ReportAccessService::class);
        $records = \App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportSavedViewRecord::query()
            ->where('organization_id', $organizationId)->where(static fn ($q) => $q->where('owner_id', $actor->id)->orWhere('visibility', 'organization'))
            ->orderBy('created_at')->orderBy('id')->limit(2001)
            ->get(['id', 'report_code', 'contract_version', 'name', 'visibility', 'status', 'is_default', 'updated_at']);
        $complete = $records->count() <= 2000;
        $allowed = [];
        foreach ($records->take(2000) as $view) {
            $code = (string) $view->report_code;
            if (! array_key_exists($code, $allowed)) {
                try {
                    $definition = $definitions->published($code)->payload();
                    $access->assertOperation($auth->context, $definition, \App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation::VIEW, null);
                    $allowed[$code] = ['version' => $definition->contractVersion, 'title' => trans_message($metadata->published($code)->titleKey)];
                } catch (\App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException|\InvalidArgumentException|\OutOfBoundsException) {
                    $allowed[$code] = null;
                }
            }
            if ($allowed[$code] === null || ! in_array($view->status, ['active', 'needs_migration'], true)) { $unavailable = true; continue; }
            $views[] = ['id' => (string) $view->id, 'report_code' => $code, 'title' => $allowed[$code]['title'], 'name' => (string) $view->name,
                'visibility' => (string) $view->visibility, 'status' => $view->contract_version === $allowed[$code]['version'] ? (string) $view->status : 'needs_migration',
                'is_default' => (bool) $view->is_default, 'updated_at' => $view->updated_at?->toIso8601String()];
        }
        return ['favourites' => $rows($workspace->favouriteReportCodes), 'recent' => $rows($workspace->recentReportCodes),
            'saved_views' => $views, 'saved_views_complete' => $complete, 'saved_views_unavailable' => $unavailable,
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
        $lines[] = trans_message('ai_assistant_operational.report_saved_views_count', ['count' => count($data['saved_views']),
            'stale' => count(array_filter($data['saved_views'], static fn (array $view): bool => $view['status'] === 'needs_migration'))]);
        foreach (array_slice($data['saved_views'], 0, 20) as $view) {
            $lines[] = '- '.self::text($view['name']).'; '.self::text($view['title']).'; '
                .trans_message('ai_assistant_operational.report_saved_view_status_'.$view['status']).'; '
                .trans_message('ai_assistant_operational.report_saved_view_visibility_'.$view['visibility']);
        }
        if (! $data['saved_views_complete']) { $lines[] = trans_message('ai_assistant_operational.report_saved_views_partial'); }
        if ($data['saved_views_unavailable']) { $lines[] = trans_message('ai_assistant_operational.report_saved_views_unavailable'); }
        $lines[] = trans_message('ai_assistant_operational.report_saved_views_basis');
        $lines[] = trans_message('ai_assistant_operational.report_workspace_basis');
        return implode("\n", $lines);
    }
}
