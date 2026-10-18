<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\Models\User;
use Throwable;

final readonly class AssistantPublishedReportPresentation
{
    public function control(string $tool, array $result, User $actor, int $organizationId): ?array
    {
        $blocked = $tool === 'generate_published_report' && ($result['status'] ?? null) === 'source_not_ready';
        if (! $blocked && ! ($tool === 'check_published_report_readiness' && ($result['status'] ?? null) === 'success')) {
            return null;
        }
        try {
            $verified = app(AssistantPublishedReportTasks::class)->verifyReadiness($result, $actor, $organizationId);
        } catch (Throwable) {
            $verified = null;
        }
        if ($verified === null) {
            return ['text' => trans_message('ai_published_reports.check_unavailable'), 'validated' => false,
                'blocked' => $blocked, 'readiness' => null];
        }
        $readiness = $verified['readiness'];
        $key = match ($readiness['state']) {
            'ready' => $blocked ? 'ready_after_block' : 'source_ready',
            'not_ready' => 'failure.'.($readiness['failure_reason'] ?? 'source_unavailable'),
            default => 'source_unchecked',
        };

        $text = trans_message('ai_published_reports.'.$key);
        foreach ($readiness['components'] ?? [] as $component) {
            $reasons = $component['ready'] ? [trans_message('ai_published_reports.component_ready')]
                : array_map(static fn (string $reason): string => trans_message('ai_published_reports.component_reasons.'.$reason), $component['reasons']);
            $text .= "\n".trans_message('ai_published_reports.components.'.$component['kind']).': '.implode(' ', $reasons);
        }

        return ['text' => $text, 'validated' => true,
            'blocked' => $blocked, 'readiness' => $readiness];
    }

    public function artifacts(array $artifacts): string
    {
        $messages = [];
        foreach ($artifacts as $artifact) {
            if (($artifact['artifact_type'] ?? null) !== 'core_report_task') {
                $messages[] = trans_message('ai_custom_reports.ready');

                continue;
            }
            $messages[] = trans_message(match ($artifact['status'] ?? null) {
                'failed' => 'ai_published_reports.failure.'.($artifact['failure_reason'] ?? 'generation_failed'),
                'ready' => 'ai_published_reports.ready',
                default => 'ai_published_reports.queued',
            });
        }

        return implode("\n\n", array_unique($messages));
    }
}
