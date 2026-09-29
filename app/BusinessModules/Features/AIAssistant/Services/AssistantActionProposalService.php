<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\Models\Conversation;
use App\BusinessModules\Features\AIAssistant\Models\Message;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final class AssistantActionProposalService
{
    public function resolve(Conversation $conversation, User $actor, array $candidate): array
    {
        $fingerprint = $this->fingerprint($candidate);
        foreach ($conversation->messages()->where('role', 'assistant')->orderByDesc('created_at')->orderByDesc('id')->limit(30)->get() as $message) {
            $metadata = $message->metadata ?? [];
            $requestId = $metadata['request_id'] ?? null;
            if ((int) ($metadata['actor_user_id'] ?? 0) !== (int) $actor->id || !is_string($requestId)) {
                continue;
            }
            if (isset($candidate['origin_request_id']) && $candidate['origin_request_id'] !== $requestId) {
                continue;
            }
            foreach ($metadata['proposed_actions'] ?? [] as $proposal) {
                if (!is_array($proposal) || !hash_equals($this->fingerprint($proposal), $fingerprint)) {
                    continue;
                }
                $origin = Message::query()->where('conversation_id', $conversation->id)->where('role', 'user')
                    ->where('metadata->request_id', $requestId)->where('metadata->actor_user_id', $actor->id)->first();
                if ($origin === null || !($origin->metadata['request']['allow_actions'] ?? false) || !($proposal['allowed'] ?? false)
                    || !$this->supportsGoal((string) $proposal['tool_name'], $origin->content)) {
                    throw new AuthorizationException(trans_message('ai_assistant.action_origin_invalid'));
                }
                return array_merge($proposal, ['allow_actions' => true, 'origin_request_id' => $requestId]);
            }
        }
        throw new AuthorizationException(trans_message('ai_assistant.action_origin_invalid'));
    }

    public function supportsGoal(string $tool, string $query): bool
    {
        $text = mb_strtolower(trim($query));
        if (preg_match('/^(?:(?:пожалуйста|можешь|можете|хочу|нужно|надо|давай|прошу)\s+)*(создай|создать|добавь|добавить|сделай|измени|изменить|обнови|обновить|переименуй|удали|удалить|утверди|утвердить|одобри|одобрить|отправь|отправить|уведоми|отметь|заверши|закрой|переведи|запланируй)\b/u', $text, $matched) !== 1) {
            return false;
        }
        $verbs = match ($tool) {
            'create_measurement_unit', 'mass_create_measurement_units', 'create_schedule_task' => ['создай', 'создать', 'добавь', 'добавить', 'сделай', 'запланируй'],
            'update_measurement_unit' => ['измени', 'изменить', 'обнови', 'обновить', 'переименуй'],
            'delete_measurement_unit' => ['удали', 'удалить'],
            'update_schedule_task_status' => ['измени', 'изменить', 'обнови', 'обновить', 'отметь', 'заверши', 'закрой', 'переведи'],
            'approve_payment_request' => ['утверди', 'утвердить', 'одобри', 'одобрить'],
            'send_project_notification' => ['отправь', 'отправить', 'уведоми'],
            default => [],
        };
        if (!in_array($matched[1], $verbs, true)) {
            return false;
        }
        $domain = match ($tool) {
            'create_measurement_unit', 'update_measurement_unit', 'delete_measurement_unit', 'mass_create_measurement_units' => 'единиц|измерен',
            'create_schedule_task', 'update_schedule_task_status' => 'задач|график|мероприят|работ',
            'approve_payment_request' => 'плат[её]ж|заявк',
            'send_project_notification' => 'уведом|сообщ|сотруд|команд|проект',
            default => null,
        };
        return $domain !== null && preg_match('/(?:'.$domain.')/u', $text) === 1;
    }

    private function fingerprint(array $proposal): string
    {
        $arguments = is_array($proposal['arguments'] ?? null) ? $proposal['arguments'] : [];
        $this->sort($arguments);
        return hash('sha256', json_encode([(string) ($proposal['tool_name'] ?? ''), $arguments], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function sort(array &$arguments): void
    {
        if (!array_is_list($arguments)) {
            ksort($arguments);
        }
        foreach ($arguments as &$value) {
            if (is_array($value)) {
                $this->sort($value);
            }
        }
    }
}
