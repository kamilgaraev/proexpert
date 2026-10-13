<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

use App\BusinessModules\Core\AccessRecertification\Services\AccessRecertificationService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class AssistantAccessRecertificationReader extends AssistantOrganizationOperationalReader
{
    public const TYPE = 'live_organization_access_recertification_projection';

    public static function hasIntent(string $query): bool
    {
        return preg_match('/(?:провер(?:к|ок)|пересмотр|ревизи|переаттестаци)[а-яё]*\s+доступ|access.?recertification/iu', $query) === 1;
    }

    public static function matches(string $query): bool
    {
        return self::hasIntent($query)
            && preg_match('/создай|измени|одобри|отзови|подтверди|удали|назначь|зарплат|оплат|бюджет|договор|поставк|табел|отпуск|больнич/iu', $query) !== 1;
    }

    protected function collect(User $actor, int $organizationId): array
    {
        if (! in_array('access_recertification', $this->access->effectiveModuleSlugs($actor, $organizationId), true)
            || ! $this->access->canCurrentPermission($actor, $organizationId, 'access_recertification.reports.view')) {
            throw new AuthorizationException;
        }

        return app(AccessRecertificationService::class)->campaignSummary($organizationId);
    }

    protected function format(array $data): string
    {
        return implode("\n", [trans_message('ai_assistant_operational.access_review_scope', ['date' => $data['as_of_date']]),
            trans_message('ai_assistant_operational.access_review_counts', ['pending' => $data['items_pending'], 'overdue' => $data['items_overdue'], 'risk' => $data['dangerous_access']]),
            trans_message('ai_assistant_operational.access_review_basis')]);
    }
}
