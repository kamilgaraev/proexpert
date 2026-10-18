<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOperationalReaderRegistry;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportEntityQuery;
use Throwable;

final class AssistantNamedProjectReadScope
{
    public static function bind(array $plan, callable $search): array
    {
        unset($plan['named_project_scope']);
        $query = (string) ($plan['request']['message'] ?? '');
        $intent = AssistantOperationalReaderRegistry::intent($query);
        if (! isset(AssistantOperationalReaderRegistry::TOOLS[$intent['name'] ?? '']) || AssistantRequestProjectScope::organizationWide($query)) {
            return $plan;
        }
        $name = self::name($plan);
        $ambiguous = AssistantReportEntityQuery::hasAmbiguousName($query, 'project');
        if ($name === null && ! $ambiguous) {
            return $plan;
        }
        $id = null;
        if ($name !== null && ! $ambiguous) {
            $arguments = ['query' => $name, 'limit' => 2];
            if (preg_match('/^(?:id\s*|№\s*|#\s*)?([1-9]\d*)$/iu', $name, $matches) === 1) {
                $arguments['project_id'] = (int) $matches[1];
            }
            try {
                $result = $search($arguments);
                $rows = is_array($result) ? ($result['results'] ?? null) : null;
                if (is_array($result) && ($result['status'] ?? null) === 'success' && is_array($rows) && count($rows) === 1
                    && ($result['result_window']['has_more'] ?? null) === false && is_int($rows[0]['id'] ?? null) && $rows[0]['id'] > 0) {
                    $id = $rows[0]['id'];
                }
            } catch (Throwable) {
                $id = null;
            }
        }
        $plan['named_project_scope'] = ['project_id' => $id, 'message_sha256' => hash('sha256', $query)];

        return $plan;
    }

    public static function name(array $plan): ?string
    {
        $query = (string) ($plan['request']['message'] ?? '');
        if (AssistantRequestProjectScope::organizationWide($query)) {
            return null;
        }
        $quoted = preg_match('/(?:проект(?:а|у|ом|е)?|объект(?:а|у|ом|е)?)(?:\s*:\s*|\s+)[«“„"]/iu', $query) === 1;
        if (AssistantRequestProjectScope::fromPlan($plan)->requested && ! $quoted) {
            return null;
        }

        $name = AssistantReportEntityQuery::extract($query, 'project');

        return ! $quoted && $name !== null && preg_match('/^(?:есть|нет|что|кто|где|когда|как|какие|какой|какая|какое|сколько|нужно|надо|можно|не|и|или|но|а|при|без|перед|после|в|во|к|у|под|над|между|о|об|со|через)(?![\pL\pN])/iu', $name) === 1 ? null : $name;
    }
}
