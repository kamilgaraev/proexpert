<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportHttpAuthorizationTargetResolver;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportScopeAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportCatalogAction;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCatalogDefinitionView;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\Models\User;

final readonly class AssistantProjectReportCatalogReader extends AssistantProjectOperationalReader
{
    public const TYPE = 'live_project_report_catalog_projection';
    public const INTENT_PATTERN = '/доступн[а-яё]*\s+отч[её]т|каталог[а-яё]*\s+отч[её]т|отч[её]т[а-яё]*[^?.!]*(?:доступн|подойдут|помогут|подходит)/iu';

    public function __construct(AssistantDataAccessPolicy $access, private ReportHttpAuthorizationTargetResolver $targets,
        private CurrentReportScopeAuthorizer $authorization, private GetReportCatalogAction $catalog)
    {
        parent::__construct($access);
    }

    public static function matches(string $query): bool
    {
        return preg_match(self::INTENT_PATTERN, $query) === 1
            && preg_match('/создай|сформируй|выгрузи|скачай|отправь|запусти|сколько|потрач|прибыли|данн|цифр|значени|фактическ|результат|показател|итоги|сформирован|сумм[а-яё]*\s+(?:расход|затрат)/iu', $query) !== 1;
    }

    protected function domain(): string { return 'assistant'; }

    protected function collect(User $actor, int $organizationId, int $projectId): array
    {
        $authorization = $this->authorization->authorizeCatalog((int) $actor->id, $organizationId, new \DateTimeZone((string) config('app.timezone', 'UTC')), $this->targets->catalog());
        $catalog = $this->catalog->handle($authorization->context, $authorization);
        $definitions = array_map(static fn (ReportCatalogDefinitionView $definition): array => ['code' => $definition->code,
            'title' => trans_message($definition->titleKey), 'category' => $definition->category, 'grain' => $definition->grain,
            'hash' => $definition->definitionHash->value, 'can_run' => $definition->visibility->canRun,
            'can_export' => $definition->visibility->canExport, 'can_download' => $definition->visibility->canDownload,
            'formats' => $definition->formats], $catalog->definitions);
        usort($definitions, static fn (array $a, array $b): int => (($a['code'] !== 'accepted_production_progress') <=> ($b['code'] !== 'accepted_production_progress')) ?: strcmp($a['code'], $b['code']));
        return ['manifest' => $catalog->manifestSha256->value, 'total' => count($definitions), 'definitions' => array_slice($definitions, 0, 20)];
    }

    protected function format(array $data): string
    {
        $lines = [trans_message('ai_assistant_operational.report_catalog_scope', ['project' => self::text($data['project']['name']), 'date' => $data['as_of_date'], 'count' => $data['total']])];
        foreach ($data['definitions'] as $definition) {
            $lines[] = '- '.self::text($definition['title']).' ('.self::text($definition['code']).'); '
                .trans_message('ai_assistant_operational.report_catalog_run_'.($definition['can_run'] ? 'allowed' : 'unconfirmed')).'; '
                .trans_message('ai_assistant_operational.report_catalog_export', ['export' => trans_message('ai_assistant_operational.permission_'.($definition['can_export'] ? 'yes' : 'no')),
                    'download' => trans_message('ai_assistant_operational.permission_'.($definition['can_download'] ? 'yes' : 'no')),
                    'formats' => implode(', ', array_map(static fn (string $format): string => strtoupper(self::text($format)), $definition['formats']))]);
        }
        foreach ($data['definitions'] as $definition) {
            if ($definition['code'] === 'accepted_production_progress') {
                $lines[] = trans_message('ai_assistant_operational.report_catalog_accepted', ['title' => self::text($definition['title'])]);
                break;
            }
        }
        $lines[] = trans_message('ai_assistant_operational.report_catalog_open', ['url' => '/reports']);
        $lines[] = trans_message('ai_assistant_operational.report_catalog_limit', ['shown' => count($data['definitions'])]);
        $lines[] = trans_message('ai_assistant_operational.report_catalog_basis');
        $lines[] = trans_message('ai_assistant_operational.report_catalog_scope_basis');
        return implode("\n", $lines);
    }
}
