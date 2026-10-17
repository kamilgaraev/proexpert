<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportHttpAuthorizationTargetResolver;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportScopeAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Subscriptions\ListReportSubscriptionsHandler;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportCatalogMetadataRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscription;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscriptionExecutionInput;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscriptionWindow;
use App\Models\User;
use App\Services\Entitlements\OrganizationEntitlementService;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class AssistantReportSubscriptionsReader extends AssistantOrganizationOperationalReader
{
    public const TYPE = 'live_personal_report_subscriptions_projection';

    public static function hasIntent(string $query): bool
    {
        return preg_match('/отч[её]т/iu', $query) === 1 && preg_match('/регулярн|автоматическ|расписан|подписк|рассылк/iu', $query) === 1;
    }

    public static function matches(string $query): bool
    {
        return self::hasIntent($query)
            && \App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantReportSubscriptionIntent::type($query) === null
            && preg_match('/создай|измени|удали|запусти|отправь|приостанови|возобнови|отмени|\bправ[а-яё]*|избран|сотрудник|договор|склад|сч[её]т/iu', $query) !== 1;
    }

    protected function collect(User $actor, int $organizationId): array
    {
        if (! $this->access->canCurrentPermission($actor, $organizationId, 'reports.view')
            || ! $this->access->canCurrentPermission($actor, $organizationId, 'reports.manage')
            || ! app(OrganizationEntitlementService::class)->hasModuleAccess($organizationId, 'reports')) {
            throw new AuthorizationException;
        }
        $auth = app(CurrentReportScopeAuthorizer::class)->authorizeCatalog((int) $actor->id, $organizationId,
            new \DateTimeZone((string) config('app.timezone', 'UTC')), app(ReportHttpAuthorizationTargetResolver::class)->catalog());
        $page = app(ListReportSubscriptionsHandler::class)->handle($auth->context, new ReportSubscriptionWindow(null, 100, null));
        $metadata = app(ReportCatalogMetadataRegistry::class);
        $inputs = [];
        foreach ($page->items as $row) {
            $inputs[$row->id] = ReportSubscriptionExecutionInput::fromCanonicalBytes($row->executionInputBytes);
        }
        $projectIds = array_values(array_unique(array_filter(array_map(
            static fn (ReportSubscriptionExecutionInput $input): ?int => $input->effectiveProjectId(), $inputs))));
        $projectNames = [];
        if ($projectIds !== [] && $this->access->canCurrentPermission($actor, $organizationId, 'projects.view')) {
            $projectNames = $this->access->entityQuery($actor, $organizationId, 'project', null)
                ->whereKey($projectIds)->pluck('name', 'id')->all();
        }

        return ['complete' => ! $page->hasMore, 'items' => array_map(static fn (ReportSubscription $row): array => [
            'id' => $row->id, 'report_code' => $row->reportCode, 'title' => trans_message($metadata->published($row->reportCode)->titleKey),
            'project_id' => $inputs[$row->id]->effectiveProjectId(), 'project_name' => $projectNames[$inputs[$row->id]->effectiveProjectId()] ?? null,
            'frequency' => $row->frequency->value, 'weekday' => $row->weekday, 'day_of_month' => $row->dayOfMonth, 'local_time' => $row->localTime,
            'timezone' => $row->timezone->getName(), 'format' => $row->format, 'status' => $row->status->value,
            'next_run_at' => $row->nextRunAt?->setTimezone($row->timezone)->format(DATE_ATOM)], $page->items)];
    }

    protected function format(array $data): string
    {
        $counts = ['active' => 0, 'paused' => 0, 'disabled' => 0];
        foreach ($data['items'] as $row) {
            $counts[$row['status']]++;
        }
        $lines = [trans_message('ai_assistant_operational.report_subscriptions_scope', ['date' => $data['as_of_date'], 'count' => count($data['items'])]),
            trans_message('ai_assistant_operational.report_subscriptions_counts', $counts)];
        foreach (array_slice($data['items'], 0, 20) as $row) {
            $lines[] = '- '.self::text($row['title']).'; '.trans_message('ai_assistant_operational.report_subscription_status_'.$row['status'])
                .($row['project_id'] === null ? '' : '; '.trans_message('ai_assistant_operational.report_subscription_project',
                    ['project' => $row['project_name'] === null ? trans_message('ai_assistant_operational.report_subscription_project_unavailable') : self::text($row['project_name'])]))
                .'; '.trans_message('ai_assistant_operational.report_subscription_frequency_'.$row['frequency'])
                .($row['frequency'] === 'weekly' ? ' ('.trans_message('ai_assistant_operational.report_subscription_weekday_'.$row['weekday']).')' : '')
                .($row['frequency'] === 'monthly' ? ' ('.trans_message('ai_assistant_operational.report_subscription_monthday', ['day' => $row['day_of_month']]).')' : '')
                .'; '.self::text($row['local_time']).' ('.self::text($row['timezone']).'); '.strtoupper($row['format'])
                .'; '.trans_message('ai_assistant_operational.report_subscription_next', ['date' => $row['next_run_at'] === null ? trans_message('ai_assistant_operational.not_recorded') : self::text($row['next_run_at'])]);
        }
        if (! $data['complete']) {
            $lines[] = trans_message('ai_assistant_operational.report_subscriptions_partial');
        }
        $lines[] = trans_message('ai_assistant_operational.report_subscriptions_basis');

        return implode("\n", $lines);
    }
}
