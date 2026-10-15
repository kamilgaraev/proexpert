<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantMutationIntent;
use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantTemporalIntent;
use App\DTOs\AdvanceAccountSettingDTO;
use App\Models\AdvanceAccountSetting;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class AssistantAdvanceSettingsReader extends AssistantOrganizationOperationalReader
{
    public const TYPE = 'live_organization_advance_settings_projection';

    public static function hasIntent(string $query): bool
    {
        return preg_match('/подотч[её]т[а-яё]*|авансов[а-яё]*\s+отч[её]т/iu', $query) === 1;
    }

    public static function matches(string $query): bool
    {
        $query = preg_replace('/ничего\s+не\s+меняй(?:те)?/iu', '', $query) ?? $query;
        $hasProjectRequirement = preg_match('/(?:обязательно|требуется|нужен|нужно|надо)\s+ли\s+(?:(?:указывать|указать|привязывать|привязать)\s+)?проект/iu', $query) === 1;
        return self::hasIntent($query)
            && ! AssistantTemporalIntent::hasNonCurrentScope($query)
            && ($hasProjectRequirement || preg_match('/настройк|лимит|правил|порог|уведомл|напомина|напомин|срок[а-яё]*\s+(?:сдачи|подотч[её]т)|сколько\s+дней\s+(?:да[её]тся|отводится|отведено|предусмотрено)/iu', $query) === 1)
            && ! self::asksAboutReportApproval($query)
            && preg_match('/№|#|(?<!\pL)(?:id|номер)(?!\pL)|остал[а-яё]*|конкретн|по\s+отч[её]ту|до\s+срок/iu', $query) !== 1
            && preg_match('/(?:каки[её]|покажи(?:те)?|перечисли(?:те)?)\s+авансов[а-яё]*\s+отч[её]т|авансов[а-яё]*\s+отч[её]т[а-яё]*\s+(?:просроч|задерж)/iu', $query) !== 1
            && preg_match('/что\s+(?:делать|будет|произойд[её]т)|каков[а-яё]*\s+последств|что\s+если/iu', $query) !== 1
            && preg_match('/^\s*(?:пожалуйста[,\s]+|а\s+)?(?:каки[её]|какой|какая|какое|каков[а-яё]*|сколько|за\s+сколько|(?:уведомля[а-яё]*|напомина[а-яё]*|обязательно|требуется|нужен|нужно|надо|будет|будут)\s+ли|что|покажи(?:те)?|назови(?:те)?|расскажи(?:те)?|перечисли(?:те)?|подскажи(?:те)?|посмотри(?:те)?|проверь(?:те)?)(?!\pL)/iu', $query) === 1
            && ! AssistantMutationIntent::containsRequest($query)
            && preg_match('/измени|установи|поменяй|настрой\s|выдай|создай|одобри|согласуй|отклони|удали|включи|отключи|отправь|мои\s+уведомлен|непрочитан|истори|событи|договор|сч[её]т|оплат|плат[её]ж|бюджет|проект[а-яё]*\s+(?:активн|архив)|какие\s+проекты|сотрудник|поставк|закуп|табел|график|задач|бим|ifc|bim/iu', $query) !== 1;
    }

    private static function asksAboutReportApproval(string $query): bool
    {
        if (preg_match('/авансов[а-яё]*\s+отч[её]т/iu', $query) === 1
            && preg_match('/согласов/iu', $query) === 1
            && preg_match('/обязательн|нужно|надо|порядок|процесс|этап|проверк/iu', $query) === 1) {
            return true;
        }
        foreach (preg_split('/[.!?;]|\s+(?:и|или)\s+/iu', $query) ?: [] as $part) {
            if (preg_match('/авансов[а-яё]*\s+отч[её]т/iu', $part) === 1
                && preg_match('/согласов/iu', $part) === 1) {
                return true;
            }
        }

        return false;
    }

    protected function collect(User $actor, int $organizationId): array
    {
        if (! in_array('advance-accounting', $this->access->effectiveModuleSlugs($actor, $organizationId), true)
            || ! $this->access->canCurrentPermission($actor, $organizationId, 'advance_settings.view')
            || ! $this->access->canCurrentPermission($actor, $organizationId, 'finance.view')) { throw new AuthorizationException; }
        $setting = AdvanceAccountSetting::query()->where('organization_id', $organizationId)->first();
        $settings = AdvanceAccountSettingDTO::fromModelOrDefaults($setting, $organizationId)->toArray();
        foreach (['max_single_issue_amount', 'dual_authorization_threshold', 'high_value_notification_threshold'] as $key) {
            $value = $setting?->getRawOriginal($key);
            $settings[$key] = $value === null ? null : (string) $value;
        }
        return ['stored' => $setting !== null, 'settings' => $settings];
    }

    protected function format(array $data): string
    {
        $settings = $data['settings'];
        $lines = [trans_message('ai_assistant_operational.advance_settings_scope', ['date' => $data['as_of_date']]),
            trans_message('ai_assistant_operational.advance_settings_'.($data['stored'] ? 'stored' : 'defaults'))];
        foreach (['max_single_issue_amount', 'report_submission_deadline_days', 'dual_authorization_threshold', 'require_project_for_expense',
            'notify_admin_on_overdue_report', 'notify_admin_on_high_value_transaction', 'high_value_notification_threshold',
            'notify_user_on_transaction_approval', 'notify_user_on_transaction_rejection', 'send_report_reminder_days_before'] as $key) {
            $value = $settings[$key];
            $text = $value === null ? trans_message('ai_assistant_operational.not_recorded')
                : (is_bool($value) ? trans_message('ai_assistant_operational.advance_settings_'.($value ? 'yes' : 'no')) : (string) $value);
            $lines[] = '- '.trans_message('ai_assistant_operational.advance_settings_'.$key).': '.$text.'.';
        }
        $lines[] = trans_message('ai_assistant_operational.advance_settings_basis');
        return implode("\n", $lines);
    }
}
