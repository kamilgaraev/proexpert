<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class AssistantReportDeliverySummaryReader extends AssistantProjectOperationalReader
{
    public const TYPE = 'live_project_report_delivery_summary_projection';

    public function __construct(AssistantDataAccessPolicy $access, private AssistantReportSubscriptionsReader $subscriptions,
        private AssistantProjectExportHistoryReader $exports)
    {
        parent::__construct($access);
    }

    public static function matches(string $query): bool
    {
        return AssistantReportSubscriptionsReader::matches($query) && AssistantProjectExportHistoryReader::matches($query)
            && preg_match('/покажи|есть\s+ли|каки[её]|провер|посмотр/iu', $query) === 1
            && preg_match('/настрой|скачай|измени|запусти|добавь|сохрани|назначь|создай|отправь|удали|уведомлен|избран|представлен|права|рол[ьи]|бюджет|задач|комплект|журнал|договор|ifc|bim|бим|модел|насос|кондиционер|вентиляц|crm|rfi|сделк|безопасност|охран|происшеств|техник|сотрудник|материал|поставк|закуп|тендер|заявк|затрат|прибыл|выручк|(?<![\p{L}\p{N}_])акт(?:ы|а|ов|ам|ами|ах|ом|у|е)?(?![\p{L}\p{N}_])/iu', $query) !== 1;
    }

    protected function domain(): string { return 'assistant'; }

    protected function collect(User $actor, int $organizationId, int $projectId): array
    {
        $sections = [];
        foreach (['subscriptions' => [$this->subscriptions, ['scope' => 'organization']], 'exports' => [$this->exports, ['project_id' => $projectId]]] as $name => [$reader, $arguments]) {
            try {
                $result = $reader->read($actor, $organizationId, $arguments);
                $sections[$name] = ['available' => true, 'data' => $result['data'], 'answer' => $result['server_formatted_answer']];
            } catch (AuthorizationException) {
                $sections[$name] = ['available' => false];
            } catch (ReportContractException $exception) {
                if ($exception->errorCode !== ReportErrorCode::REPORT_SCOPE_FORBIDDEN) { throw $exception; }
                $sections[$name] = ['available' => false];
            }
        }
        return ['sections' => $sections];
    }

    protected function format(array $data): string
    {
        $lines = [];
        foreach ($data['sections'] as $name => $section) {
            $lines[] = trans_message('ai_assistant_operational.report_delivery_'.$name);
            $lines[] = $section['available'] ? $section['answer'] : trans_message('ai_assistant_operational.report_delivery_unavailable');
        }
        return implode("\n\n", $lines);
    }
}
