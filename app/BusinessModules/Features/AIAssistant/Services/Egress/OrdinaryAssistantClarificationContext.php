<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Egress;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantEgressDenied;
use App\BusinessModules\Features\AIAssistant\Models\AssistantEgressCohort;
use App\BusinessModules\Features\AIAssistant\Models\AssistantEgressEnrollment;
use App\BusinessModules\Features\AIAssistant\Models\AssistantRequest;
use App\BusinessModules\Features\AIAssistant\Models\Message;
use App\BusinessModules\Features\AIAssistant\Services\AssistantClarificationOutcome;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\ConversationManager;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final readonly class OrdinaryAssistantClarificationContext
{
    public const VERSION = 'ordinary-clarification-context/1';
    public const KEY = 'clarification_continuation';
    public const MAX_PARENT_CHARS = 2000;
    public const MAX_PARENT_BYTES = 8000;
    public const MAX_REPLY_CHARS = 4000;
    public const MAX_REPLY_BYTES = 16000;

    private const RECEIPT_FIELDS = ['version', 'parent_request_id', 'user_message_id', 'assistant_message_id', 'user_question_hash',
        'original_request_hash', 'missing', 'clarification_hash', 'actor_id', 'organization_id', 'conversation_id', 'project_id',
        'cohort_id', 'cohort_generation', 'policy_version', 'parent_enrollment_id', 'parent_manifest_hash'];

    public function __construct(private ConversationManager $conversations, private AssistantDataAccessPolicy $access) {}

    public static function envelope(array $payload): ?array
    {
        $context = $payload['context'] ?? [];
        if (! is_array($context) || ! array_key_exists(self::KEY, $context)) { return null; }
        $value = $context[self::KEY];
        if (! is_array($value) || count($value) !== 3 || ($value['version'] ?? null) !== self::VERSION
            || ! is_string($value['parent_request_id'] ?? null) || ! Str::isUuid($value['parent_request_id'])
            || ! is_string($value['context_hash'] ?? null) || preg_match('/\A[0-9a-f]{64}\z/D', $value['context_hash']) !== 1) {
            throw new AssistantEgressDenied('unsupported_purpose');
        }
        return ['version' => self::VERSION, 'parent_request_id' => $value['parent_request_id'], 'context_hash' => $value['context_hash']];
    }

    public static function questionAllowed(string $question): bool
    {
        return self::boundedText($question, self::MAX_PARENT_CHARS, self::MAX_PARENT_BYTES);
    }

    public static function replyAllowed(string $reply): bool
    {
        return self::boundedText($reply, self::MAX_REPLY_CHARS, self::MAX_REPLY_BYTES);
    }

    private static function boundedText(string $text, int $characters, int $bytes): bool
    {
        return trim($text) !== '' && mb_check_encoding($text, 'UTF-8') && mb_strlen($text) <= $characters && strlen($text) <= $bytes;
    }

    public static function manifestHash(array $binding, ?array $context): string
    {
        if ($context !== null) { $binding['input_context'] = $context; }
        return OrdinaryAssistantSourceProjection::hash($binding);
    }

    public function record(AssistantRequest $request, Message $user, Message $assistant, AssistantClarificationOutcome $outcome,
        AssistantEgressCohort $cohort, AssistantEgressEnrollment $admission): ?array
    {
        if ($outcome->missing === null || $admission->input_context !== null || ! self::questionAllowed($user->content)) { return null; }
        if ($request->user_id !== (int) $cohort->actor_id || $request->organization_id !== (int) $cohort->organization_id
            || $admission->cohort_id !== $cohort->id || $admission->cohort_generation !== $cohort->generation
            || $assistant->content !== $outcome->verification()['text'] || $request->status !== 'running' || $request->conversation_id !== (int) $user->conversation_id
            || (int) $assistant->conversation_id !== $request->conversation_id || $user->role !== 'user' || $assistant->role !== 'assistant'
            || ($user->metadata['request_id'] ?? null) !== $request->request_id
            || ($user->metadata['actor_user_id'] ?? null) !== $request->user_id
            || ! hash_equals($request->request_hash, $admission->input_hash)) {
            throw new AssistantEgressDenied('source_unknown');
        }
        $receipt = ['version' => self::VERSION, 'parent_request_id' => $request->request_id,
            'user_message_id' => (int) $user->id, 'assistant_message_id' => (int) $assistant->id,
            'user_question_hash' => hash('sha256', $user->content), 'original_request_hash' => $request->request_hash,
            'missing' => $outcome->missing, 'clarification_hash' => hash('sha256', $outcome->verification()['text']),
            'actor_id' => $request->user_id, 'organization_id' => $request->organization_id, 'conversation_id' => $request->conversation_id,
            'project_id' => (int) $cohort->project_id, 'cohort_id' => $cohort->id, 'cohort_generation' => $cohort->generation,
            'policy_version' => $cohort->policy_version, 'parent_enrollment_id' => $admission->id, 'parent_manifest_hash' => $admission->manifest_hash];
        DB::transaction(function () use ($request, $receipt): void {
            $current = AssistantRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($current->status !== 'running' || $current->clarification_receipt !== null) { throw new AssistantEgressDenied('source_unknown'); }
            $current->forceFill(['clarification_receipt' => $receipt])->save();
        });
        return ['version' => self::VERSION, 'parent_request_id' => $request->request_id,
            'context_hash' => OrdinaryAssistantSourceProjection::hash($receipt)];
    }

    public function preview(array $input, User $actor, AssistantEgressCohort $cohort, ?int $reviewerId): ?array
    {
        try {
            $envelope = self::envelope($input);
            if ($envelope === null) { return null; }
            if (! is_int($input['conversation_id'] ?? null) || ! is_string($input['message'] ?? null) || ! self::replyAllowed($input['message'])) { throw new AssistantEgressDenied('scope_mismatch'); }
            return $this->resolve($envelope, $actor, $cohort, $input['conversation_id'], $reviewerId);
        } catch (AssistantEgressDenied $denied) {
            throw $denied;
        } catch (Throwable $failure) {
            throw OrdinaryAssistantExceptionBoundary::sanitize($failure);
        }
    }

    public function refresh(array $proof, User $actor, AssistantEgressCohort $cohort, ?int $reviewerId): array
    {
        try {
            if (count($proof) !== 5 || ! is_int($proof['conversation_id'] ?? null) || ! is_string($proof['projection_hash'] ?? null)) {
                throw new AssistantEgressDenied('source_unknown');
            }
            $envelope = self::envelope(['context' => [self::KEY => array_intersect_key($proof, array_flip(['version', 'parent_request_id', 'context_hash']))]]);
            $current = $this->resolve($envelope ?? [], $actor, $cohort, $proof['conversation_id'], $reviewerId);
            if (! hash_equals(OrdinaryAssistantSourceProjection::hash($proof), OrdinaryAssistantSourceProjection::hash($current['proof']))) {
                throw new AssistantEgressDenied('source_stale');
            }
            return $current;
        } catch (AssistantEgressDenied $denied) {
            throw $denied;
        } catch (Throwable $failure) {
            throw OrdinaryAssistantExceptionBoundary::sanitize($failure);
        }
    }

    private function resolve(array $envelope, User $actor, AssistantEgressCohort $cohort, int $conversationId, ?int $reviewerId): array
    {
        return $this->access->withCurrentChecks($actor, (int) $cohort->organization_id,
            fn (): array => $this->resolveCurrent($envelope, $actor, $cohort, $conversationId, $reviewerId), true);
    }

    private function resolveCurrent(array $envelope, User $actor, AssistantEgressCohort $cohort, int $conversationId, ?int $reviewerId): array
    {
        if ((int) $actor->id !== (int) $cohort->actor_id) { throw new AssistantEgressDenied('scope_mismatch'); }
        $reviewer = app(OrdinaryAssistantSourceCatalog::class)->freshReviewer($reviewerId, (int) $cohort->organization_id);
        if ($this->access->withCurrentChecks($reviewer, (int) $cohort->organization_id,
            fn () => $this->conversations->findAccessibleConversation($conversationId, $reviewer, (int) $cohort->organization_id, false), true) === null) {
            throw new AssistantEgressDenied('access_denied');
        }
        $parent = AssistantRequest::query()->where('request_id', $envelope['parent_request_id'])
            ->where('organization_id', $cohort->organization_id)->where('user_id', $actor->id)->first();
        $receipt = $parent?->clarification_receipt;
        if ($parent === null || $parent->status !== 'completed' || $parent->completed_at === null || $parent->cancel_requested_at !== null
            || ! is_array($receipt) || count($receipt) !== count(self::RECEIPT_FIELDS)
            || array_diff(self::RECEIPT_FIELDS, array_keys($receipt)) !== []
            || array_filter(array_intersect_key($receipt, array_flip(['user_message_id', 'assistant_message_id', 'actor_id', 'organization_id', 'conversation_id', 'project_id'])),
                static fn (mixed $id): bool => ! is_int($id) || $id < 1) !== []
            || array_filter(array_intersect_key($receipt, array_flip(['user_question_hash', 'original_request_hash', 'clarification_hash', 'parent_manifest_hash'])),
                static fn (mixed $hash): bool => ! is_string($hash) || preg_match('/\A[0-9a-f]{64}\z/D', $hash) !== 1) !== []
            || ! is_string($receipt['parent_enrollment_id'] ?? null) || ! Str::isUuid($receipt['parent_enrollment_id']) || ($receipt['version'] ?? null) !== self::VERSION
            || ! hash_equals($envelope['context_hash'], OrdinaryAssistantSourceProjection::hash($receipt))
            || ($receipt['parent_request_id'] ?? null) !== $parent->request_id || ($receipt['actor_id'] ?? null) !== (int) $actor->id
            || ($receipt['organization_id'] ?? null) !== (int) $cohort->organization_id || ($receipt['conversation_id'] ?? null) !== $conversationId
            || $parent->conversation_id !== $conversationId || ($receipt['project_id'] ?? null) !== (int) $cohort->project_id
            || ($receipt['cohort_id'] ?? null) !== $cohort->id || ($receipt['cohort_generation'] ?? null) !== $cohort->generation
            || ($receipt['policy_version'] ?? null) !== $cohort->policy_version
            || ($receipt['original_request_hash'] ?? null) !== $parent->request_hash
            || ! in_array($receipt['missing'] ?? null, ['period', 'focus', 'goal'], true)
            || $this->conversations->findAccessibleConversation($conversationId, $actor, (int) $cohort->organization_id, true) === null) {
            throw new AssistantEgressDenied('access_denied');
        }
        $admission = AssistantEgressEnrollment::query()->whereKey($receipt['parent_enrollment_id'])->where('cohort_id', $cohort->id)
            ->where('cohort_generation', $cohort->generation)->where('input_hash', $parent->request_hash)->first();
        if ($admission === null || $admission->input_context !== null || $admission->approved_at === null
            || $admission->declaration_version !== OrdinaryAssistantEnrollmentService::DECLARATION
            || $admission->manifest_hash !== $receipt['parent_manifest_hash']
            || ! hash_equals($admission->manifest_hash, self::manifestHash(['cohort' => $cohort->id,
                'cohort_generation' => $cohort->generation, 'input_hash' => $admission->input_hash, 'sources' => $admission->sources], null))) {
            throw new AssistantEgressDenied('source_unknown');
        }
        $user = Message::query()->whereKey($receipt['user_message_id'])->where('conversation_id', $conversationId)->where('role', 'user')->first();
        $assistant = Message::query()->whereKey($receipt['assistant_message_id'])->where('conversation_id', $conversationId)->where('role', 'assistant')->first();
        $text = trans_message('ai_assistant.clarification_'.$receipt['missing']);
        $response = $parent->response;
        if ($user === null || $assistant === null || ! self::questionAllowed($user->content)
            || ($user->metadata['actor_user_id'] ?? null) !== (int) $actor->id || ($user->metadata['request_id'] ?? null) !== $parent->request_id
            || ! hash_equals($receipt['user_question_hash'], hash('sha256', $user->content))
            || ! hash_equals($receipt['clarification_hash'], hash('sha256', $text)) || $assistant->content !== $text
            || ($assistant->metadata['request_id'] ?? null) !== $parent->request_id || ($assistant->metadata['actor_user_id'] ?? null) !== (int) $actor->id
            || ($assistant->metadata['request_state'] ?? null) !== 'completed' || ($assistant->metadata['needs_clarification'] ?? null) !== true
            || ($assistant->metadata['source_refs'] ?? null) !== [] || ($assistant->metadata['validation_status'] ?? null) !== 'unverified'
            || ($assistant->metadata['outcome'] ?? null) !== 'insufficient_data'
            || ! is_array($assistant->metadata[self::KEY] ?? null)
            || OrdinaryAssistantSourceProjection::hash($assistant->metadata[self::KEY]) !== OrdinaryAssistantSourceProjection::hash($envelope) || ($response['status'] ?? null) !== 'completed'
            || ($response['message']['id'] ?? null) !== (int) $assistant->id || ($response['message']['content'] ?? null) !== $text
            || ! is_array($response['message']['metadata'][self::KEY] ?? null)
            || OrdinaryAssistantSourceProjection::hash($response['message']['metadata'][self::KEY]) !== OrdinaryAssistantSourceProjection::hash($envelope)) {
            throw new AssistantEgressDenied('source_stale');
        }
        $preview = ['version' => self::VERSION, 'previous_user_question' => $user->content, 'clarification' => $text];
        return ['proof' => $envelope + ['conversation_id' => $conversationId, 'projection_hash' => OrdinaryAssistantSourceProjection::hash($preview)],
            'preview' => $preview];
    }
}
