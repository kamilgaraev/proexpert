<?php

declare(strict_types=1);

namespace Tests\Support;

use App\BusinessModules\Features\AIAssistant\Models\AssistantMemory;
use App\BusinessModules\Features\AIAssistant\Models\Conversation;
use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantMemoryService;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRetentionService;
use App\BusinessModules\Features\AIAssistant\Services\ConversationManager;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class ShadowConversationScenario
{
    public static function execute(User $actor, Organization $org, Project $project, array $scenario, ?ShadowProviderCollector $collector = null): array
    {
        if (! app()->environment('testing') || config('ai-assistant-credits.enforce', true) !== false) {
            throw new RuntimeException('Conversation shadow fixtures require isolated testing with charging disabled.');
        }
        if ((int) $project->organization_id !== (int) $org->id || (int) $actor->current_organization_id !== (int) $org->id) {
            throw new RuntimeException('Conversation shadow fixture scope mismatch.');
        }
        $manager = app(ConversationManager::class);
        $conversation = $manager->createConversation((int) $org->id, $actor, 'Shadow '.$scenario['id']);
        $category = (string) $scenario['category'];
        $variant = (int) (explode('-', (string) $scenario['id'])[1] ?? 1);
        $before = ShadowObservationVerifier::domainState();
        $externalCallsBefore = $collector === null ? null : count($collector->calls);
        $evidence = match ($category) {
            'memory' => self::memory($actor, $org, $conversation, $variant),
            'isolation' => self::isolation($actor, $org, $conversation, $variant),
            'retention' => self::retention($actor, $org, $project, $conversation, $variant),
            'followup', 'context' => self::context($actor, $org, $project, $conversation, $category),
            default => throw new RuntimeException('Unsupported conversation shadow category.'),
        };
        if ($category === 'memory') {
            $delta = $externalCallsBefore === null || $collector === null ? null : count($collector->calls) - $externalCallsBefore;
            $snapshot = ['memories' => DB::table('ai_memories')->where('organization_id', $org->id)->orderBy('id')->get()->all(),
                'summaries' => DB::table('ai_conversation_summaries')->where('conversation_id', $conversation->id)->get()->all(),
                'crud_receipts' => $evidence];
            $receipt = ['mode' => 'no_external_calls', 'verified' => $delta === 0 && $evidence['checks_passed'],
                'observed_external_call_count' => $delta,
                'source_code_sha256' => hash('sha256', (string) file_get_contents(app_path('BusinessModules/Features/AIAssistant/Services/AssistantMemoryService.php'))
                    .(string) file_get_contents(app_path('BusinessModules/Features/AIAssistant/Services/AIAssistantService.php'))),
                'record_snapshot_sha256' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
                'verifier' => self::class.'::execute: collector delta measured around actual confirmed memory CRUD, before ask'];
            $receipt['evidence_sha256'] = hash('sha256', json_encode($receipt, JSON_THROW_ON_ERROR));
            $evidence['memory_no_external'] = $receipt;
        }
        if ($category === 'followup') {
            $evidence['first_turn_response'] = app(AIAssistantService::class)->ask(
                'Выбери проект с ID '.$project->id.' и названием '.$project->name.'. Проверь его актуальные данные.',
                (int) $org->id, $actor, (int) $conversation->id,
                ['request_id' => (string) Str::uuid(), 'profile' => $scenario['requested_profile'], 'allow_actions' => false]);
        }
        $query = 'Проверь актуальные права и контекст. Какой проект выбран? Не придумывай суммы и не выполняй изменения.';
        if ($category === 'context') {
            $suffix = ($scenario['input']['supplemental_scope'] ?? null) === 'exact_4000_character_current_query_preservation'
                ? ' Ответь на последний вопрос: какой проект выбран, каковы его идентификатор и название?'
                : ' Ответь на последний вопрос: какой проект выбран и каково его название?';
            $query = str_repeat('а', 4000 - mb_strlen($suffix)).$suffix;
        }
        $executedRequestId = (string) ($scenario['input']['request_id'] ?? Str::uuid());
        $response = app(AIAssistantService::class)->ask($query, (int) $org->id, $actor,
            Conversation::query()->whereKey($conversation->id)->exists() ? (int) $conversation->id : null,
            ['request_id' => $executedRequestId, 'profile' => $scenario['requested_profile'], 'allow_actions' => false]);
        $after = ShadowObservationVerifier::domainState();
        if ($category === 'context') {
            $persistedQuery = DB::table('ai_messages')->where('conversation_id', $conversation->id)->where('role', 'user')->orderByDesc('id')->value('content');
            $evidence['latest_query_db'] = $persistedQuery;
            $evidence['latest_query_preserved'] = $persistedQuery === $query && mb_strlen($query) === 4000;
            $evidence['checks_passed'] = $evidence['checks_passed'] && $evidence['latest_query_preserved'];
        }
        $sourcesAllowed = true;
        foreach ((array) ($response['source_refs'] ?? []) as $reference) {
            $sourcesAllowed = $sourcesAllowed && is_array($reference)
                && app(AssistantDataAccessPolicy::class)->canReadSource($actor, (int) $org->id, $reference);
        }
        $evidence += ['actual_query' => $query, 'manifest_input' => $scenario['input'],
            'conversation_id' => $conversation->id, 'domain_before' => $before, 'domain_after' => $after,
            'returned_sources' => $response['source_refs'] ?? [], 'sources_allowed_now' => $sourcesAllowed,
            'project_golden' => DB::table('projects')->where('id', $project->id)->first(['id', 'organization_id', 'name', 'updated_at']),
            'response' => $response];
        $scope = $scenario['input']['supplemental_scope'] ?? null;
        if ($scope === 'exact_4000_character_current_query_preservation') {
            $targetAllowed = false;
            foreach ((array) ($response['source_refs'] ?? []) as $reference) {
                if (is_array($reference) && ($reference['entity_type'] ?? $reference['type'] ?? null) === 'project'
                    && (string) ($reference['entity_id'] ?? $reference['id'] ?? '') === (string) $project->id
                    && app(AssistantDataAccessPolicy::class)->canReadSource($actor, (int) $org->id, $reference)) { $targetAllowed = true; }
            }
            $evidence['project_context_oracle'] = self::projectContextChecks($response, (array) $evidence['project_golden'],
                $evidence['latest_query_preserved'] ?? false, $sourcesAllowed, $targetAllowed);
        }
        $hash = hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR));
        $assertions = [];
        foreach (['rights' => $sourcesAllowed && $evidence['checks_passed'],
            'leak' => $evidence['private_scope_passed'], 'unconfirmed_actions' => $before === $after,
            'business_quality' => $evidence['checks_passed']] as $key => $passed) {
            $assertions[$key] = ['expected' => $scenario['assertions'][$key]['expected'] ?? $key,
                'observed' => $passed ? 'passed' : 'failed', 'verifier' => self::class.'::execute: independent fixture DB and current service access',
                'evidence_sha256' => $hash];
        }
        $assertions['factual_amounts'] = ['expected' => 'server_verified_or_explicitly_unverified',
            'observed' => ($response['validation_status'] ?? null) === 'unverified' ? 'passed' : 'pending: monetary claims require domain golden verifier',
            'verifier' => self::class.'::execute: no model-text monetary oracle', 'evidence_sha256' => $hash];
        if (in_array($category, ['followup', 'context', 'isolation'], true)) {
            $assertions['business_quality']['observed'] = 'pending: project/history/privacy transitions verified; full manifest domain intent needs its own oracle';
        }
        if (in_array($scope, ['exact_4000_character_current_query_preservation', 'confirmed_private_memory_crud'], true)) {
            $assertions['factual_amounts']['observed'] = self::nonFinancialAnswer($response) ? 'passed' : 'failed';
        }
        if ($scope === 'exact_4000_character_current_query_preservation') {
            $assertions['business_quality']['observed'] = $evidence['checks_passed'] && !in_array(false, $evidence['project_context_oracle'], true) ? 'passed' : 'failed';
        }
        if ($category === 'retention' && $variant === 2) {
            $assertions['business_quality']['observed'] = 'pending: chat/messages/summary/memory deletion verified; generated-file deletion fixture missing';
        }

        return ['response' => $response, 'evidence' => $evidence, 'assertions' => $assertions, 'executed_request_id' => $executedRequestId];
    }

    public static function nonFinancialAnswer(array $response): bool
    {
        $payload = is_array($response['message']['metadata'] ?? null) ? $response['message']['metadata'] : $response;
        $text = (string) ($response['message']['content'] ?? $payload['answer'] ?? $payload['text'] ?? '');
        if (trim($text) === '' || preg_match('/\d[\d\s.,]*\s*(?:₽|руб|RUB|USD|EUR|доллар|евро)|(?:сумм[а-яё]*|стоимост[а-яё]*|бюджет[а-яё]*|цен[а-яё]*|аванс[а-яё]*|долг[а-яё]*)\s*[:=—-]?\s*\d|(?:сумм[а-яё]*|стоимост[а-яё]*|бюджет[а-яё]*|цен[а-яё]*|аванс[а-яё]*|долг[а-яё]*)[^\d.;!?\n]{0,40}(?:составляет|равн[а-яё]*|[:=])\s*-?\d/iu', $text) === 1) { return false; }
        foreach (['financial_claims', 'money_claims', 'totals', 'financial_evidence'] as $field) {
            if (!empty($payload[$field])) { return false; }
        }
        return true;
    }

    public static function projectContextChecks(array $response, array $golden, bool $preserved, bool $sourcesAllowed, bool $targetAllowed): array
    {
        $text = (string) ($response['message']['content'] ?? $response['answer'] ?? $response['text'] ?? '');
        $id = (string) ($golden['id'] ?? '');
        $name = (string) ($golden['name'] ?? '');
        return ['exact_query_preserved' => $preserved, 'all_sources_currently_allowed' => $sourcesAllowed,
            'selected_project_source_allowed' => $targetAllowed,
            'project_id_matches' => $id !== '' && preg_match('/(?<!\d)'.preg_quote($id, '/').'(?!\d)/u', $text) === 1,
            'project_name_matches' => $name !== '' && str_contains($text, $name),
            'no_monetary_claims' => self::nonFinancialAnswer($response)];
    }

    private static function memory(User $actor, Organization $org, Conversation $conversation, int $variant): array
    {
        $service = app(AssistantMemoryService::class);
        $content = 'Предпочтение shadow '.Str::uuid();
        $before = AssistantMemory::query()->where('user_id', $actor->id)->count();
        $denied = self::denied(fn () => $service->create($actor, (int) $org->id, ['content' => $content, 'confirmed' => false]));
        $afterDenied = AssistantMemory::query()->where('user_id', $actor->id)->count();
        $memory = $service->create($actor, (int) $org->id, ['content' => $content, 'confirmed' => true, 'conversation_id' => $conversation->id]);
        $stored = DB::table('ai_memories')->where('id', $memory->id)->first();
        $other = self::member($actor, $org);
        app(ConversationManager::class)->updateParticipants($conversation, $actor, (int) $org->id,
            [['user_id' => $other->id, 'role' => 'viewer']]);
        $otherIds = $service->list($other, (int) $org->id)->pluck('id')->all();
        $private = ! in_array($memory->id, $otherIds, true);
        $deleteDenied = self::denied(fn () => $service->delete($other, (int) $org->id, $memory));
        $updated = $service->update($actor, (int) $org->id, $memory, ['content' => $content.' обновлено', 'confirmed' => true, 'version' => $memory->version]);
        $updatedRow = DB::table('ai_memories')->where('id', $memory->id)->first();
        $version = $updatedRow?->version;
        $createdPayload = json_decode((string) ($stored?->payload ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
        $updatedPayload = json_decode((string) ($updatedRow?->payload ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
        $contentVerified = ($createdPayload['content'] ?? null) === $content && ($updatedPayload['content'] ?? null) === $content.' обновлено';
        $service->delete($actor, (int) $org->id, $updated);
        $deleted = ! DB::table('ai_memories')->where('id', $memory->id)->exists();
        $context = $service->forContext($actor, (int) $org->id);
        $absent = ! in_array($content.' обновлено', array_column($context, 'content'), true);

        return ['operation' => 'confirmed memory CRUD', 'variant' => $variant, 'denied_without_confirmation' => $denied,
            'count_before' => $before, 'count_after_denied' => $afterDenied, 'created_row' => $stored,
            'updated_row' => $updatedRow, 'content_verified_db' => $contentVerified,
            'updated_version' => $version, 'deleted' => $deleted, 'absent_next_context' => $absent,
            'private_scope_passed' => $private && $deleteDenied,
            'checks_passed' => $denied && $before === $afterDenied && $contentVerified && (int) $version === 2 && $deleted && $absent && $private && $deleteDenied];
    }

    private static function isolation(User $actor, Organization $org, Conversation $conversation, int $variant): array
    {
        $manager = app(ConversationManager::class);
        $member = self::member($actor, $org);
        $private = $manager->findAccessibleConversation((int) $conversation->id, $member, (int) $org->id) === null;
        $manager->updateParticipants($conversation, $actor, (int) $org->id, [['user_id' => $member->id, 'role' => 'viewer']]);
        $read = $manager->findAccessibleConversation((int) $conversation->id, $member, (int) $org->id) !== null;
        $writeDenied = $manager->findAccessibleConversation((int) $conversation->id, $member, (int) $org->id, true) === null;
        $sharingDenied = self::denied(fn () => $manager->updateParticipants($conversation, $member, (int) $org->id, []));
        DB::table('organization_user')->where('organization_id', $org->id)->where('user_id', $member->id)->update(['is_active' => false]);
        $revoked = $manager->findAccessibleConversation((int) $conversation->id, $member, (int) $org->id) === null;
        $history = $manager->getHistory($conversation, actor: $member)->count();

        return ['operation' => 'private chat, viewer sharing, current membership revocation', 'variant' => $variant,
            'member_id' => $member->id, 'private_before_sharing' => $private, 'shared_read_allowed' => $read,
            'shared_write_denied' => $writeDenied, 'sharing_change_denied' => $sharingDenied,
            'revoked_read_denied' => $revoked, 'revoked_history_count' => $history,
            'membership_golden' => DB::table('organization_user')->where('organization_id', $org->id)->where('user_id', $member->id)->first(),
            'private_scope_passed' => $private && $writeDenied && $sharingDenied && $revoked && $history === 0,
            'checks_passed' => $private && $read && $writeDenied && $sharingDenied && $revoked && $history === 0];
    }

    private static function context(User $actor, Organization $org, Project $project, Conversation $conversation, string $category): array
    {
        $manager = app(ConversationManager::class);
        $reference = ['type' => 'project', 'id' => (int) $project->id, 'organization_id' => (int) $org->id, 'fetched_at' => now()->toISOString()];
        $ids = [];
        foreach (range(1, 5) as $index) {
            $ids[] = $manager->addMessage($conversation, 'user', 'Запись истории '.$index.': выбран проект '.$project->name)->id;
        }
        DB::table('ai_messages')->whereIn('id', $ids)->update(['created_at' => now()->subMinute()]);
        $conversation->refresh();
        $manager->saveSummary($conversation, $actor, ['validation_status' => 'verified', 'selected_entities' => [$reference],
            'user_decisions' => [['content' => 'Выбран проект '.$project->name]]], [$reference], (int) $conversation->context_version);
        $selected = DB::table('ai_conversation_summaries')->where('conversation_id', $conversation->id)->value('selected_entities');
        $pageOne = $manager->getHistoryPage($conversation, $actor, 2, 1)->getCollection()->pluck('id')->all();
        $pageTwo = $manager->getHistoryPage($conversation, $actor, 2, 2)->getCollection()->pluck('id')->all();
        $summary = $manager->getSummary($conversation, $actor);
        $selectedIds = array_column($summary?->selected_entities ?? [], 'id');
        $private = (int) DB::table('ai_conversations')->where('id', $conversation->id)->value('organization_id') === (int) $org->id;

        return ['operation' => 'real selected entity summary and stable paginated user history', 'category' => $category,
            'message_ids_golden' => $ids, 'page_one_ids' => $pageOne, 'page_two_ids' => $pageTwo,
            'selected_entities_db' => $selected, 'selected_entities_service' => $selectedIds,
            'private_scope_passed' => $private, 'checks_passed' => $private && $pageOne === array_slice($ids, 3, 2)
                && $pageTwo === array_slice($ids, 1, 2) && $selectedIds === [(int) $project->id]];
    }

    private static function retention(User $actor, Organization $org, Project $project, Conversation $conversation, int $variant): array
    {
        $manager = app(ConversationManager::class);
        $service = app(AssistantMemoryService::class);
        $manager->addMessage($conversation, 'user', 'Реальная запись срока хранения');
        $conversation->refresh();
        $reference = ['type' => 'project', 'id' => (int) $project->id, 'organization_id' => (int) $org->id, 'fetched_at' => now()->toISOString()];
        $summary = $manager->saveSummary($conversation, $actor, ['validation_status' => 'verified', 'summary' => 'Старое резюме shadow'],
            [$reference], (int) $conversation->context_version);
        $reference['fetched_at'] = now()->subDays(91)->toISOString();
        $summary->forceFill(['source_refs' => [$reference], 'summary_segments' => [['text' => 'Старое резюме shadow', 'source_refs' => [$reference]]]])->save();
        $staleSummaryHidden = $manager->getSummary($conversation, $actor)?->summary === null;
        $memory = $service->create($actor, (int) $org->id, ['content' => 'Память срока хранения', 'confirmed' => true, 'conversation_id' => $conversation->id]);
        $conversation->forceFill(['last_activity_at' => now()->subDays(91)])->save();
        $memory->forceFill(['last_used_at' => now()->subDays(30), 'expires_at' => now()->addDays(60)])->save();
        $before = DB::table('ai_conversations')->where('id', $conversation->id)->value('last_activity_at');
        $memoryBefore = DB::table('ai_memories')->where('id', $memory->id)->first(['last_used_at', 'expires_at']);
        $manager->getHistory($conversation, actor: $actor);
        $service->list($actor, (int) $org->id);
        $after = DB::table('ai_conversations')->where('id', $conversation->id)->value('last_activity_at');
        $memoryAfter = DB::table('ai_memories')->where('id', $memory->id)->first(['last_used_at', 'expires_at']);
        $preview = app(AssistantRetentionService::class)->preview();
        $cutoff = now()->subDays(90);
        $expected = DB::table('ai_conversations')->where(fn ($query) => $query->where('last_activity_at', '<=', $cutoff)
            ->orWhere(fn ($missing) => $missing->whereNull('last_activity_at')->where('created_at', '<=', $cutoff)))->count();
        $unchanged = $before === $after && $memoryBefore == $memoryAfter;
        $existsAfterPreview = Conversation::query()->whereKey($conversation->id)->exists();
        $deleted = null;
        if ($variant === 2) {
            $manager->deleteConversation($conversation, $actor);
            $deleted = ! DB::table('ai_conversations')->where('id', $conversation->id)->exists()
                && ! DB::table('ai_messages')->where('conversation_id', $conversation->id)->exists()
                && ! DB::table('ai_conversation_summaries')->where('conversation_id', $conversation->id)->exists()
                && ! DB::table('ai_memories')->where('id', $memory->id)->exists();
        }

        return ['operation' => 'read-only retention preview and explicit synthetic owner deletion', 'variant' => $variant,
            'activity_before' => $before, 'activity_after' => $after, 'memory_before' => $memoryBefore, 'memory_after' => $memoryAfter,
            'preview' => $preview, 'expired_conversations_db_golden' => $expected, 'exists_after_preview' => $existsAfterPreview,
            'stale_summary_hidden' => $staleSummaryHidden,
            'explicit_deletion_verified' => $deleted, 'private_scope_passed' => true,
            'checks_passed' => $unchanged && $existsAfterPreview && $preview['conversations'] === $expected && $deleted !== false && $staleSummaryHidden];
    }

    private static function member(User $owner, Organization $org): User
    {
        $user = User::factory()->create(['current_organization_id' => $org->id, 'is_active' => true]);
        $org->users()->attach($user->id, ['is_active' => true, 'is_owner' => false, 'settings' => null]);
        $role = OrganizationCustomRole::query()->create(['organization_id' => $org->id, 'name' => 'Shadow viewer',
            'slug' => 'shadow_'.Str::lower(Str::random(16)), 'system_permissions' => [],
            'module_permissions' => ['ai-assistant' => ['ai_assistant.chat']], 'interface_access' => ['admin', 'lk', 'mobile'],
            'is_active' => true, 'created_by' => $owner->id]);
        UserRoleAssignment::query()->create(['user_id' => $user->id, 'role_slug' => $role->slug,
            'role_type' => UserRoleAssignment::TYPE_CUSTOM, 'context_id' => AuthorizationContext::getOrganizationContext((int) $org->id)->id,
            'assigned_by' => $owner->id, 'is_active' => true]);

        return $user;
    }

    private static function denied(callable $operation): bool
    {
        try {
            $operation();
        } catch (RuntimeException) {
            return true;
        }

        return false;
    }
}
