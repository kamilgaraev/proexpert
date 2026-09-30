<?php

declare(strict_types=1);

namespace Tests\Support;

use App\BusinessModules\Features\AIAssistant\Models\AssistantRequest;
use App\BusinessModules\Features\AIAssistant\Models\Conversation;
use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\AssistantActionService;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainReadService;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestLifecycle;
use App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportAccessService;
use App\Models\Credits\AICreditReservation;
use App\Models\MeasurementUnit;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Support\AI\TokenBudgetService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Throwable;

final class ShadowActionBillingScenario
{
    public static function prepare(array $scenario): array
    {
        $variant = self::variant($scenario);
        $scenario['input']['qa_flow'] = match ($scenario['category']) {
            'actions' => match ($variant) {
                1 => 'Actual model proposes a measurement unit; real preview must not change unit rows.',
                2 => 'Actual model proposes a unit update; change its server state and require confirmation rejection.',
                default => 'Actual model receives allow_actions=false; no mutation proposal or write is accepted.',
            },
            'billing' => match ($variant) {
                1 => 'Replay the exact completed request ID; measure provider-call delta, response and reservation journal.',
                2 => 'Read actual completed request, frozen approved maximum and shadow charge; paid cap enforcement remains pending.',
                default => 'Cancel an actual queued lifecycle request; verify closed reservation. Nonzero paid-reserve release remains pending in shadow.',
            },
            'tokens' => match ($variant) {
                1 => 'Send an oversized current query through ask and measure rejection before provider calls.',
                2 => 'Build the real token budget with real registered schemas, then run a real model request. Multi-tool output-context transition remains pending.',
                default => 'Build the requested profile budget and measure actual provider output usage against its output ceiling.',
            },
            'reports' => 'Register a real project-scoped report-access receipt and resolve its token; revoke current membership and require denial. No report file is generated.',
            'navigation' => 'Read the real project navigation receipt; revoked membership must deny a fresh receipt. Browser opening and deleted-parent transition remain pending.',
            'races' => 'Real model probe only. Concurrent execution is pending; sequential calls cannot prove this oracle.',
            'errors' => 'Real model probe with captured provider outcome. Timeout/outage/tool failure is pending unless actually observed.',
            default => throw new RuntimeException('Unsupported action/billing shadow category.'),
        };
        $scenario['oracle_scope'] = ['live_model_or_measured_pre_provider_rejection', 'current_service_and_database_receipts',
            'no_manufactured_provider_usage', 'no_paid_charging_or_readiness_approval', 'unsupported_transitions_remain_pending'];
        return $scenario;
    }

    public static function execute(User $actor, Organization $org, Project $project, array $scenario, ?ShadowProviderCollector $collector = null): array
    {
        if (! app()->environment('testing') || config('ai-assistant-credits.enforce', true) !== false) {
            throw new RuntimeException('Action/billing shadow fixtures require isolated testing with charging disabled.');
        }
        if ((int) $project->organization_id !== (int) $org->id || (int) $actor->current_organization_id !== (int) $org->id) {
            throw new RuntimeException('Action/billing shadow fixture scope mismatch.');
        }
        $offset = $collector === null ? null : count($collector->calls);
        $before = ShadowObservationVerifier::domainState();
        $result = match ($scenario['category']) {
            'actions' => self::actions($actor, $org, $scenario, $collector),
            'billing' => self::billing($actor, $org, $project, $scenario, $collector),
            'tokens' => self::tokens($actor, $org, $project, $scenario, $collector),
            'reports' => self::reports($actor, $org, $project, $scenario),
            'navigation' => self::navigation($actor, $org, $project, $scenario),
            'races', 'errors' => self::unsupported($actor, $org, $project, $scenario),
            default => throw new RuntimeException('Unsupported action/billing shadow category.'),
        };
        $after = ShadowObservationVerifier::domainState();
        unset($before['ai_credit_ledger_entries'], $after['ai_credit_ledger_entries']);
        $evidence = $result['evidence'] + ['business_before' => $before, 'business_after' => $after,
            'journal_scope' => 'Zero-amount shadow lifecycle journals are evaluated separately; no paid charging is enabled.',
            'scenario_id' => $scenario['id'], 'manifest_input' => $scenario['input'],
            'actor_id' => $actor->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'provider_call_count' => $offset === null || $collector === null ? null : count($collector->calls) - $offset];
        $hash = hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR));
        $assertions = [];
        $noWrite = $before === $after && ! str_starts_with($result['no_unconfirmed_write'] ?? '', 'failed:');
        foreach (['business_quality' => $result['quality'], 'unconfirmed_actions' => $noWrite ? 'passed: business-row hashes unchanged; explicit fixture-only unit transitions separately verified' : 'failed: unexpected monitored business write'] as $key => $observed) {
            $assertions[$key] = ['expected' => $scenario['expectations'][$key] ?? $key, 'observed' => $observed,
                'verifier' => self::class.'::execute: actual service transitions and independent DB/collector receipts', 'evidence_sha256' => $hash];
        }
        if (isset($result['rights'])) {
            $assertions['rights'] = ['expected' => $scenario['expectations']['rights'] ?? 'current_actor_scope_enforced',
                'observed' => $result['rights'], 'verifier' => self::class.'::execute: real current access service', 'evidence_sha256' => $hash];
        }
        return ['response' => $result['response'], 'evidence' => $evidence, 'assertions' => $assertions]
            + array_intersect_key($result, array_flip(['error', 'expected_rejection']));
    }

    private static function actions(User $actor, Organization $org, array $scenario, ?ShadowProviderCollector $collector): array
    {
        $variant = self::variant($scenario);
        $name = 'Единица shadow '.Str::uuid();
        $unit = $variant === 2 ? MeasurementUnit::query()->create(['organization_id' => $org->id, 'name' => $name,
            'short_name' => 'qa-'.Str::random(12), 'type' => 'material']) : null;
        $query = $variant === 2 ? 'Измени единицу измерения с ID '.$unit->id.' и названием '.$name.': новое имя '.$name.' новое.'
            : 'Создай единицу измерения с названием '.$name.', обозначением qa и типом material. Подготовь предварительный просмотр без выполнения.';
        $before = self::unitState((int) $org->id);
        $response = self::ask($actor, $org, $scenario, $query, $variant !== 3);
        $afterAsk = self::unitState((int) $org->id);
        $evidence = ['actual_query' => $query, 'allow_actions' => $variant !== 3, 'units_before' => $before,
            'units_after_ask' => $afterAsk, 'proposal' => $response['proposed_actions'] ?? [], 'response' => $response];
        $quality = 'pending: model did not provide a usable server-origin proposal';
        if ($variant === 3) {
            $quality = ($response['proposed_actions'] ?? []) === [] && $before === $afterAsk
                ? 'passed: actions disabled, no mutation proposal and no unit write' : 'failed: action appeared or unit state changed with actions disabled';
        } else {
            $proposal = collect($response['proposed_actions'] ?? [])->first(fn (array $action): bool => ($action['tool_name'] ?? null) === ($variant === 2 ? 'update_measurement_unit' : 'create_measurement_unit'));
            if (is_array($proposal) && isset($response['conversation_id'])) {
                $conversation = Conversation::query()->findOrFail($response['conversation_id']);
                $service = app(AssistantActionService::class);
                try {
                    $preview = $service->preview($proposal, (int) $org->id, $actor, $conversation);
                    $afterPreview = self::unitState((int) $org->id);
                    $evidence += ['preview' => $preview, 'units_after_preview' => $afterPreview];
                    if ($variant === 1) {
                        $quality = $before === $afterAsk && $before === $afterPreview
                            ? 'passed: real server-origin preview produced without a measurement-unit write' : 'failed: preview or ask changed measurement units';
                    } else {
                        $unit->update(['name' => $name.' изменено вне помощника']);
                        $changed = self::unitState((int) $org->id);
                        $denial = self::rejection(fn () => $service->execute(['id' => $preview['action']['id'],
                            'preview_token' => $preview['preview_token'], 'confirmed' => true], (int) $org->id, $actor, $conversation));
                        $afterExecute = self::unitState((int) $org->id);
                        $evidence += ['units_after_fixture_change' => $changed, 'units_after_rejected_execution' => $afterExecute, 'execution_rejection' => $denial];
                        $quality = $denial !== null && $changed === $afterExecute && $before === $afterPreview
                            ? 'passed: confirmation rejected changed entity, no mutation executed' : 'failed: changed entity confirmation was not safely rejected';
                    }
                } catch (AuthorizationException $exception) {
                    $evidence['preview_denied'] = $exception::class;
                    $quality = 'pending: actor cannot preview this mutation; privileged proposal transition not exercised';
                }
            }
        }
        return ['response' => $response, 'evidence' => $evidence, 'quality' => $quality,
            'no_unconfirmed_write' => $before === $afterAsk && ! str_starts_with($quality, 'failed:') ? 'passed: unit writes absent outside explicit fixture transition' : 'failed: unexpected unit write'];
    }

    private static function billing(User $actor, Organization $org, Project $project, array $scenario, ?ShadowProviderCollector $collector): array
    {
        $query = 'Дай краткую рекомендацию по проекту '.$project->name.' с ID '.$project->id.'. Не сохраняй отчёт и не меняй данные.';
        if (self::variant($scenario) === 3) {
            $payload = ['request_id' => $scenario['input']['request_id'], 'message' => $query, 'profile' => $scenario['requested_profile'], 'allow_actions' => false];
            $service = app(AssistantRequestLifecycle::class);
            $started = $service->start($org, $actor, null, $payload);
            $request = $started['request'];
            $status = $service->cancel($request->request_id, $actor, (int) $org->id);
            $service->fail($request, 'shadow_cancel_before_provider');
            $reservation = AICreditReservation::query()->findOrFail($request->reservation_id);
            $evidence = ['actual_payload' => $payload, 'cancel_status' => $status, 'final_status' => $service->status($request->request_id, $actor, (int) $org->id),
                'reservation' => $reservation->only(['status', 'reserved_minor', 'consumed_minor']), 'cancellation_stage' => 'queued_before_first_provider'];
            $closed = $reservation->status === 'cancelled' && (int) $reservation->consumed_minor === 0;
            return ['response' => null, 'error' => 'expected_queued_cancellation', 'expected_rejection' => true, 'evidence' => $evidence,
                'quality' => $closed ? 'pending: queued cancellation verified; nonzero reserve and between-provider cancellation require paid/concurrent evidence' : 'failed: cancelled request reservation did not close',
                'no_unconfirmed_write' => $closed ? 'passed: no charge and no business mutation on queued cancellation' : 'failed: unexpected cancellation charge'];
        }
        $response = self::ask($actor, $org, $scenario, $query);
        $request = AssistantRequest::query()->where('organization_id', $org->id)->where('request_id', $scenario['input']['request_id'])->firstOrFail();
        $reservation = AICreditReservation::query()->findOrFail($request->reservation_id);
        $evidence = ['actual_query' => $query, 'request' => $request->only(['request_id', 'status', 'approved_max_minor', 'calls_used', 'max_calls']),
            'reservation' => $reservation->only(['status', 'reserved_minor', 'consumed_minor']), 'response' => $response];
        $quality = 'pending: actual frozen maximum and zero shadow charge recorded; paid cap cannot be proved with charging disabled';
        if (self::variant($scenario) === 1) {
            $offset = $collector === null ? null : count($collector->calls);
            $journal = self::reservationJournal((int) $reservation->id);
            $replay = self::ask($actor, $org, $scenario, $query);
            $after = self::reservationJournal((int) $reservation->id);
            $delta = $collector === null || $offset === null ? null : count($collector->calls) - $offset;
            $same = self::canonical($response) === self::canonical($replay);
            $evidence += ['replay' => $replay, 'journal_before_replay' => $journal, 'journal_after_replay' => $after, 'replay_provider_calls' => $delta];
            $quality = $same && $journal === $after && $delta === 0 ? 'passed: exact request replay preserves response and billing journal without another provider call'
                : ($delta === null ? 'pending: collector required to prove replay call delta' : 'failed: replay changed response, journal or provider call count');
        }
        return ['response' => $response, 'evidence' => $evidence, 'quality' => $quality,
            'no_unconfirmed_write' => (int) $reservation->consumed_minor === 0 ? 'passed: shadow reservation has no debit' : 'failed: shadow reservation debited'];
    }

    private static function tokens(User $actor, Organization $org, Project $project, array $scenario, ?ShadowProviderCollector $collector): array
    {
        $variant = self::variant($scenario);
        $query = $variant === 1 ? str_repeat('Сверхдлинный текущий вопрос без сокращения. ', 5000)
            : 'Опиши проект '.$project->name.' с ID '.$project->id.' и назови следующий безопасный шаг. Не меняй данные.';
        $offset = $collector === null ? null : count($collector->calls);
        $response = null;
        $error = null;
        $tokenRejection = false;
        try { $response = self::ask($actor, $org, $scenario, $query); } catch (Throwable $exception) {
            $error = $exception::class;
            $tokenRejection = $exception instanceof DomainException && $exception->getMessage() === 'ai_token_budget_exhausted';
        }
        $calls = $offset === null || $collector === null ? null : array_slice($collector->calls, $offset);
        $evidence = ['actual_query_sha256' => hash('sha256', $query), 'actual_query_characters' => mb_strlen($query),
            'error_class' => $error, 'token_budget_rejection' => $tokenRejection, 'response' => $response, 'observed_provider_calls' => $calls];
        if ($variant === 1) {
            $quality = $tokenRejection && $calls === [] ? 'passed: actual oversized query rejected for token budget before any provider call'
                : ($calls === null ? 'pending: collector required for pre-provider rejection' : 'failed: oversized input did not reject before provider');
        } else {
            $messages = [['role' => 'system', 'content' => 'Сохраняй права и не выполняй изменения.'], ['role' => 'user', 'content' => $query]];
            $definitions = app(AIToolRegistry::class)->getToolsDefinitions();
            $plain = app(TokenBudgetService::class)->prepare($messages, [], $scenario['requested_profile']);
            $withTools = app(TokenBudgetService::class)->prepare($messages, $definitions, $scenario['requested_profile']);
            $evidence += ['plain_budget' => $plain, 'registered_tool_budget' => $withTools];
            $quality = $variant === 2 ? ($withTools['tools_tokens'] > 0 && $withTools['raw_input_tokens'] > $plain['raw_input_tokens']
                ? 'pending: real registered schema token cost verified; multi-tool output-context transition needs live tool chain' : 'failed: registered schemas missing from input budget')
                : ($response !== null && $error === null && $calls !== null && array_filter($calls, fn (array $call): bool => $call['generative'] && ($call['usage_source'] ?? null) === 'provider_response') !== []
                    && array_filter($calls, fn (array $call): bool => $call['generative'] && (($call['usage_source'] ?? null) !== 'provider_response' || $call['output_tokens'] > $withTools['max_completion_tokens'])) === []
                    ? 'passed: actual provider outputs fit the configured profile ceiling' : 'pending: generative provider output evidence required');
        }
        return ['response' => $response, 'evidence' => $evidence, 'quality' => $quality,
            'no_unconfirmed_write' => 'pending: token probe requires outer business-row verifier']
            + ($error === null ? [] : ['error' => $error, 'expected_rejection' => $variant === 1 && $tokenRejection]);
    }

    private static function reports(User $actor, Organization $org, Project $project, array $scenario): array
    {
        $service = app(AssistantReportAccessService::class);
        $reference = ['entity_type' => 'project', 'entity_id' => (string) $project->id];
        $receipt = null;
        $denied = false;
        try { $receipt = $service->register('org-'.$org->id.'/reports/shadow/'.Str::uuid().'.pdf', $org, $actor, [$reference], ['projects']); }
        catch (AccessDeniedHttpException $exception) { $denied = true; }
        $evidence = ['actual_source_reference' => $reference, 'receipt' => $receipt, 'registration_denied' => $denied, 'report_file_generated' => false];
        if ($receipt !== null) {
            $token = basename(dirname($receipt['download_url']));
            $resolved = $service->resolve($token, $actor);
            $membership = DB::table('organization_user')->where('organization_id', $org->id)->where('user_id', $actor->id);
            $active = $membership->value('is_active');
            try {
                $membership->update(['is_active' => false]);
                $denial = self::rejection(fn () => $service->resolve($token, $actor));
            } finally { $membership->update(['is_active' => $active]); }
            $evidence += ['resolved_receipt_id' => $resolved->id, 'revoked_membership_rejection' => $denial];
            $denied = $denial !== null;
        }
        $query = 'Составь текстовый обзор доступных данных проекта '.$project->name.' с ID '.$project->id.'. Не создавай файл и не сохраняй отчёт.';
        $response = self::ask($actor, $org, $scenario, $query);
        $evidence += ['actual_query' => $query, 'response' => $response];
        return ['response' => $response, 'evidence' => $evidence,
            'quality' => 'pending: scoped receipt and membership transition exercised; report generation/download file and spreadsheet rendering not exercised',
            'rights' => $denied ? 'passed: receipt registration or revoked current membership denied by real access service' : 'failed: revoked membership retained receipt access'];
    }

    private static function navigation(User $actor, Organization $org, Project $project, array $scenario): array
    {
        $service = app(AssistantDomainReadService::class);
        $arguments = ['domain' => 'projects', 'entity_type' => 'project', 'id' => (int) $project->id];
        $receipt = null;
        $denial = null;
        try { $receipt = $service->execute('navigation', $arguments, $actor, (int) $org->id); }
        catch (AccessDeniedHttpException $exception) { $denial = $exception::class; }
        $policy = app(AssistantDataAccessPolicy::class);
        $allowed = ($receipt['source_refs'] ?? []) !== [];
        foreach ($receipt['source_refs'] ?? [] as $reference) { $allowed = $allowed && $policy->canReadSource($actor, (int) $org->id, $reference); }
        $membership = DB::table('organization_user')->where('organization_id', $org->id)->where('user_id', $actor->id);
        $active = $membership->value('is_active');
        try {
            $membership->update(['is_active' => false]);
            $revoked = self::rejection(fn () => $service->execute('navigation', $arguments, $actor, (int) $org->id));
        } finally { $membership->update(['is_active' => $active]); }
        $query = 'Найди доступный проект '.$project->name.' с ID '.$project->id.' и дай его текущую ссылку. Не меняй данные.';
        $response = self::ask($actor, $org, $scenario, $query);
        return ['response' => $response, 'evidence' => ['actual_query' => $query, 'server_navigation_receipt' => $receipt,
            'current_source_access' => $allowed, 'initial_access_rejection' => $denial, 'revoked_membership_rejection' => $revoked,
            'project_golden' => $project->fresh()->only(['id', 'organization_id', 'name']), 'response' => $response],
            'quality' => 'pending: real scoped navigation receipt verified; browser opening and deleted-parent transition not exercised',
            'rights' => ($allowed || $denial !== null || ($receipt['source_refs'] ?? []) === []) && $revoked !== null
                ? 'passed: current navigation scope checked and revoked membership denied' : 'failed: revoked membership retained navigation access'];
    }

    private static function unsupported(User $actor, Organization $org, Project $project, array $scenario): array
    {
        $query = 'Дай короткую рекомендацию по проекту '.$project->name.' с ID '.$project->id.'. Не выполняй изменений.';
        $response = self::ask($actor, $org, $scenario, $query);
        return ['response' => $response, 'evidence' => ['actual_query' => $query, 'response' => $response,
            'requested_transition' => $scenario['input']['message'], 'transition_executed' => false,
            'regression_reference_only' => $scenario['category'] === 'races' ? 'tests/Feature/AIAssistant/AICreditConcurrencyTest.php; not executed or attached by this helper' : null],
            'quality' => 'pending: '.($scenario['category'] === 'races' ? 'parallel executor and actual interleaving evidence required' : 'actual provider/tool failure and external usage evidence required')];
    }

    private static function ask(User $actor, Organization $org, array $scenario, string $query, bool $allowActions = false): array
    {
        return app(AIAssistantService::class)->ask($query, (int) $org->id, $actor, null,
            ['request_id' => $scenario['input']['request_id'], 'profile' => $scenario['requested_profile'], 'allow_actions' => $allowActions]);
    }

    private static function unitState(int $organizationId): array
    {
        return MeasurementUnit::query()->where('organization_id', $organizationId)->orderBy('id')->get()->map(fn (MeasurementUnit $unit): array => $unit->getAttributes())->all();
    }

    private static function reservationJournal(int $reservationId): array
    {
        $reservation = AICreditReservation::query()->findOrFail($reservationId);
        return ['reservation' => $reservation->getAttributes(),
            'provider_usage' => DB::table('ai_credit_provider_usages')->where('ai_credit_reservation_id', $reservationId)->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all(),
            'ledger' => DB::table('ai_credit_ledger_entries')->where('organization_id', $reservation->organization_id)
                ->where('reference_type', 'reservation')->where('reference_id', $reservation->public_id)->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all()];
    }

    private static function rejection(callable $operation): ?string
    {
        try { $operation(); } catch (AuthorizationException|AccessDeniedHttpException|DomainException|RuntimeException $exception) { return $exception::class; }
        return null;
    }

    private static function canonical(array $value): array
    {
        if (! array_is_list($value)) { ksort($value); }
        foreach ($value as $key => $item) { if (is_array($item)) { $value[$key] = self::canonical($item); } }
        return $value;
    }

    private static function variant(array $scenario): int
    {
        return (int) (explode('-', (string) $scenario['id'])[1] ?? 1);
    }
}
