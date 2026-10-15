<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

use App\BusinessModules\Core\Reporting\Application\Queries\ProjectReportExportHistory;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\Models\User;

final readonly class AssistantProjectExportHistoryReader extends AssistantProjectOperationalReader
{
    public const TYPE = 'live_project_report_export_history_projection';
    public const INTENT_PATTERN = '/выгруз[а-яё]*[\s\S]*(?:готов|обрабатыва|ошиб|заверш|очеред|истори|статус)|(?:готов|обрабатыва|ошиб|очеред|истори|статус)[\s\S]*выгруз/iu';

    public function __construct(AssistantDataAccessPolicy $access, private ProjectReportExportHistory $history)
    {
        parent::__construct($access);
    }

    public static function matches(string $query): bool
    {
        return preg_match(self::INTENT_PATTERN, $query) === 1 && preg_match('/создай|выгрузи|скачай|отмени|повтори|перезапусти|удали|отправь|\bправ[а-яё]*|сотрудник|сч[её]т|расход|затрат|склад|уведомлен|контакт/iu', $query) !== 1;
    }

    protected function domain(): string { return 'assistant'; }

    protected function collect(User $actor, int $organizationId, int $projectId): array
    {
        return array_intersect_key($this->history->read((int) $actor->id, $organizationId, $projectId), array_flip(['items', 'complete']));
    }

    protected function format(array $data): string
    {
        $counts = array_fill_keys(['queued', 'running', 'uploading', 'ready', 'failed', 'cancelled', 'expired'], 0);
        foreach ($data['items'] as $row) {
            $status = $row['status'] === 'ready' && $row['retention_expired'] ? 'expired' : $row['status'];
            $counts[$status]++;
        }
        $lines = [trans_message('ai_assistant_operational.export_history_scope', ['project' => self::text($data['project']['name']), 'date' => $data['as_of_date']]),
            trans_message('ai_assistant_operational.export_history_counts', ['total' => count($data['items']), 'ready' => $counts['ready'],
                'processing' => $counts['queued'] + $counts['running'] + $counts['uploading'], 'failed' => $counts['failed'],
                'cancelled' => $counts['cancelled'], 'expired' => $counts['expired']])];
        foreach (array_slice($data['items'], 0, 20) as $row) {
            $lines[] = '- '.self::text($row['title']).'; '.strtoupper($row['format']).'; '
                .trans_message('ai_assistant_operational.export_status_'.$row['status'])
                .($row['retention_expired'] && $row['status'] !== 'expired' ? '; '.trans_message('ai_assistant_operational.export_status_expired') : '')
                .'; '.trans_message('ai_assistant_operational.export_history_created', ['date' => self::text($row['created_at'])]);
        }
        if (! $data['complete']) {
            $lines[] = trans_message('ai_assistant_operational.export_history_partial');
        }
        $lines[] = trans_message('ai_assistant_operational.export_history_basis', ['shown' => min(count($data['items']), 20)]);
        return implode("\n", $lines);
    }
}
