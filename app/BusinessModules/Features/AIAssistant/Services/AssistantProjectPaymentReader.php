<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Models\PaymentApproval;
use App\BusinessModules\Core\Payments\Services\PaymentBudgetLimitService;
use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantMutationIntent;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final readonly class AssistantProjectPaymentReader
{
    public const TYPE = 'live_project_payment_readiness_projection';
    public function __construct(private AssistantDataAccessPolicy $access, private PaymentBudgetLimitService $budgetLimits) {}

    public static function matches(string $query): bool
    {
        $invoiceLimitCheck = preg_match('/превыш|нарушен|наруша|укладыва|исключен|перед\s+оплат|готов[а-яё]*\s+к\s+оплат/iu', $query) === 1
            && preg_match('/перечисл[а-яё]*.*лимит|(?:список|перечень|каталог).*лимит|(?:все|кажд[а-яё]*)\s+бюджетн[а-яё]*\s+лимит|по\s+всем\s+стать|лимит.*(?:организац|стать|отдел|всех\s+проект)/iu', $query) !== 1;
        $otherTopics = $invoiceLimitCheck ? (preg_replace('/бюджетн[а-яё]*\s+лимит[а-яё]*|лимит[а-яё]*\s+бюджет[а-яё]*/iu', '', $query) ?? $query) : $query;
        return ! AssistantMutationIntent::containsRequest($query)
            && preg_match('/сч[её]т[а-яё]*|плат[её]жн[а-яё]*\s+документ[а-яё]*/iu', $query) === 1
            && preg_match('/не\s+оплачен|неоплачен|перед\s+оплатой|жд[её]т.*оплат|готов[а-яё]*\s+к\s+оплат|просроч|задержива/iu', $query) === 1
            && preg_match('/создай|оплати|одобри|утверди|удали|бюджет|потрач|расход|затрат|прибыл|выручк|график|задач|табел|смет|поставк|замечан|сотрудник|техник|машин|механизм|бим|bim|ifc|(?<![\p{L}\p{N}_])акт[а-яё]*(?![\p{L}\p{N}_])/iu', $otherTopics) !== 1;
    }

    public function read(User $actor, int $organizationId, array $arguments): array
    {
        $projectId = $arguments['project_id'] ?? null;
        $offset = $arguments['offset'] ?? 0;
        if (! is_int($projectId) || $projectId < 1 || ! is_int($offset) || $offset < 0 || $offset > 9999
            || array_diff(array_keys($arguments), ['project_id', 'offset']) !== []) { throw new \InvalidArgumentException('invalid_payment_readiness_scope'); }
        return $this->access->withCurrentChecks($actor, $organizationId, function () use ($actor, $organizationId, $projectId, $offset): array {
            if (! $this->access->canReadDomain($actor, $organizationId, 'assistant')
                || (! $this->access->canCurrentPermission($actor, $organizationId, 'payments.invoice.view')
                    && ! $this->access->canCurrentPermission($actor, $organizationId, 'payments.invoice.view_all'))
                || ! $this->access->canReadEntity($actor, $organizationId, 'project', $projectId)) { throw new AuthorizationException; }
            $project = $this->access->entityQuery($actor, $organizationId, 'project')?->whereKey($projectId)->first(['id', 'name']);
            $query = $this->access->entityQuery($actor, $organizationId, 'payment_document');
            if ($project === null || $query === null) { throw new AuthorizationException; }
            $documents = $query->where('project_id', $projectId)->where('document_type', 'invoice')->whereNotIn('status', ['paid', 'cancelled', 'rejected'])
                ->orderByRaw('due_date ASC NULLS LAST')->orderBy('id')->limit(10001)->get(['id', 'document_number', 'direction', 'status',
                    'amount', 'paid_amount', 'remaining_amount', 'currency', 'due_date', 'overdue_since', 'updated_at']);
            $rows = $documents->map(static fn ($document): array => $document->getAttributes() + ['is_overdue' => $document->isOverdue()])->all();
            $items = []; $unknown = 0;
            if (count($rows) <= 10000) {
                foreach ($rows as $row) {
                    if (! self::money($row['amount']) || ! self::money($row['paid_amount']) || ! self::money($row['remaining_amount'])
                        || ! BigDecimal::of($row['amount'])->minus($row['paid_amount'])->isEqualTo(BigDecimal::of($row['remaining_amount']))) { $unknown++; continue; }
                    if (! BigDecimal::of($row['remaining_amount'])->isGreaterThan(0)) { continue; }
                    $status = PaymentDocumentStatus::tryFrom($row['status']);
                    $direction = InvoiceDirection::tryFrom((string) $row['direction']);
                    $items[] = ['id' => (int) $row['id'], 'number' => $row['document_number'], 'amount' => $row['amount'], 'paid_amount' => $row['paid_amount'],
                        'remaining_amount' => $row['remaining_amount'], 'currency' => $row['currency'], 'status' => $row['status'], 'status_label' => $status?->label(),
                        'direction' => $row['direction'], 'direction_label' => $direction?->description(), 'due_date' => $row['due_date'],
                        'status_allows_payment' => $status?->canBePaid() ?? false, 'is_overdue' => (bool) $row['is_overdue']];
                }
            }
            $window = array_slice($items, $offset, 20);
            $budgetChecks = $this->budgetChecks($actor, $organizationId, $projectId, array_column($window, 'id'));
            $approvals = $this->pendingApprovals($actor, $organizationId, array_column($window, 'id'));
            foreach ($window as &$item) {
                $item['budget_limit_check'] = $budgetChecks[$item['id']] ?? null;
                $item['approvals'] = $approvals['documents'][$item['id']] ?? ['total' => $approvals['available'] ? 0 : null, 'unnamed' => $approvals['available'] ? 0 : null, 'examples' => []];
            }
            unset($item);
            $payload = ['project' => ['id' => $projectId, 'name' => $project->name], 'as_of_date' => today()->toDateString(), 'complete' => count($rows) <= 10000,
                'total' => count($rows) <= 10000 ? count($items) : null, 'unknown_balance_count' => count($rows) <= 10000 ? $unknown : null, 'items' => $window,
                'overdue_outgoing' => count($rows) <= 10000 ? count(array_filter($items, static fn (array $item): bool => $item['is_overdue'] && $item['direction'] === 'outgoing')) : null,
                'overdue_incoming' => count($rows) <= 10000 ? count(array_filter($items, static fn (array $item): bool => $item['is_overdue'] && $item['direction'] === 'incoming')) : null,
                'approval_read_available' => $approvals['available'],
                'offset' => $offset, 'has_more' => count($rows) <= 10000 && $offset + count($window) < count($items),
                'next_offset' => count($rows) <= 10000 && $offset + count($window) < count($items) ? $offset + count($window) : null,
                'budget_check_coverage' => ['attempted' => count($window), 'available' => count(array_filter($budgetChecks, static fn (?array $check): bool => $check !== null)),
                    'outside_page' => count($rows) <= 10000 ? count($items) - count($window) : null]];
            $version = AssistantSourceReferenceIdentity::key(['schema' => 1, 'organization_id' => $organizationId, 'actor_id' => (int) $actor->id, 'payload' => $payload, 'rows' => $rows]);
            $ref = ['entity_type' => self::TYPE, 'entity_id' => $projectId, 'project_id' => $projectId, 'organization_id' => $organizationId,
                'actor_id' => (int) $actor->id, 'source_version' => $version, 'fetched_at' => now()->toIso8601String()];
            $ref['offset'] = $offset;
            return ['status' => 'partial', 'source_refs' => [$ref], 'data' => $payload, 'server_formatted_answer' => self::format($payload)];
        }, true);
    }

    public function matchesReference(User $actor, int $organizationId, array $ref): bool
    {
        if (($ref['entity_type'] ?? null) !== self::TYPE || ($ref['organization_id'] ?? null) !== $organizationId || ($ref['actor_id'] ?? null) !== (int) $actor->id
            || ($ref['project_id'] ?? null) !== ($ref['entity_id'] ?? null) || ! is_int($ref['entity_id'] ?? null) || ! is_string($ref['source_version'] ?? null)
            || ! is_int($ref['offset'] ?? 0) || ($ref['offset'] ?? 0) < 0 || ($ref['offset'] ?? 0) > 9999) { return false; }
        try { return $this->read($actor, $organizationId, ['project_id' => $ref['entity_id'], 'offset' => $ref['offset'] ?? 0])['source_refs'][0]['source_version'] === $ref['source_version']; }
        catch (\Throwable) { return false; }
    }

    public function verifiedAnswer(array $result, User $actor, int $organizationId): ?array
    {
        $ref = $result['source_refs'][0] ?? null;
        if (! is_array($ref) || ! $this->matchesReference($actor, $organizationId, $ref)) { return null; }
        $current = $this->read($actor, $organizationId, ['project_id' => $ref['entity_id'], 'offset' => $ref['offset'] ?? 0]);
        if ($current['source_refs'][0]['source_version'] !== $ref['source_version']) { return null; }
        return ['text' => $current['server_formatted_answer'], 'source_refs' => $current['source_refs'], 'validation_status' => 'partial', 'replaced' => true, 'needs_clarification' => false];
    }

    private static function format(array $data): string
    {
        $lines = [trans_message('ai_assistant_decisions.payment_scope', ['project' => self::text($data['project']['name']), 'date' => $data['as_of_date']])];
        if (! $data['complete']) { $lines[] = trans_message('ai_assistant_decisions.payment_limit'); return implode("\n", $lines); }
        $lines[] = trans_message('ai_assistant_decisions.payment_count', ['total' => $data['total'], 'shown' => count($data['items']), 'unknown' => $data['unknown_balance_count']]);
        $lines[] = trans_message('ai_assistant_decisions.payment_overdue_counts', ['outgoing' => $data['overdue_outgoing'], 'incoming' => $data['overdue_incoming']]);
        $lines[] = trans_message('ai_assistant_decisions.payment_budget_coverage', ['attempted' => $data['budget_check_coverage']['attempted'],
            'available' => $data['budget_check_coverage']['available'], 'outside' => $data['budget_check_coverage']['outside_page']]);
        if ($data['next_offset'] !== null) {
            $lines[] = trans_message('ai_assistant_decisions.payment_next_page', ['next' => $data['next_offset'] + 1]);
        }
        foreach ($data['items'] as $item) {
            $lines[] = '- '.self::text((string) $item['number']).' (#'.$item['id'].'): '.trans_message('ai_assistant_decisions.payment_amounts',
                ['amount' => $item['amount'], 'paid' => $item['paid_amount'], 'remaining' => $item['remaining_amount'], 'currency' => self::text((string) $item['currency'])]);
            $lines[] = self::text((string) $item['direction_label']).'; '.self::text((string) $item['status_label']).'. '.trans_message('ai_assistant_decisions.payment_due', ['date' => $item['due_date'] ?? '—']);
            if ($item['is_overdue']) { $lines[] = trans_message('ai_assistant_decisions.payment_overdue'); }
            $budget = $item['budget_limit_check'];
            if ($budget === null) { $lines[] = trans_message('ai_assistant_decisions.payment_budget_unknown'); }
            else {
                $lines[] = trans_message('ai_assistant_decisions.payment_budget_result', ['message' => self::text($budget['message'])]);
                if ($budget['excess_amount'] !== null) {
                    $lines[] = trans_message('ai_assistant_decisions.payment_budget_excess', ['amount' => $budget['excess_amount'], 'currency' => self::text($budget['currency'])]);
                }
                if ($budget['decision'] === 'require_exception') {
                    $lines[] = trans_message($budget['override_applied'] ? 'ai_assistant_decisions.payment_budget_override_recorded' : 'ai_assistant_decisions.payment_budget_exception_required');
                } elseif ($budget['decision'] === 'block') { $lines[] = trans_message('ai_assistant_decisions.payment_budget_blocked'); }
            }
            if (! $data['approval_read_available']) { $lines[] = trans_message('ai_assistant_decisions.payment_approvals_unavailable'); }
            elseif ($item['approvals']['total'] === 0) { $lines[] = trans_message('ai_assistant_decisions.payment_no_pending_approver'); }
            else {
                $lines[] = trans_message('ai_assistant_decisions.payment_pending_approvals', ['total' => $item['approvals']['total'], 'unnamed' => $item['approvals']['unnamed']]);
                foreach ($item['approvals']['examples'] as $approval) { $lines[] = '- '.self::text($approval['name']); }
            }
            $lines[] = trans_message($item['direction'] === 'outgoing' && $item['status_allows_payment'] ? 'ai_assistant_decisions.payment_status_ready' : 'ai_assistant_decisions.payment_status_check');
        }
        $lines[] = trans_message('ai_assistant_decisions.payment_basis');
        return implode("\n", $lines);
    }

    private function budgetChecks(User $actor, int $organizationId, int $projectId, array $ids): array
    {
        if ($ids === []) { return []; }
        $documents = $this->access->entityQuery($actor, $organizationId, 'payment_document')
            ?->where('project_id', $projectId)->where('document_type', 'invoice')->whereIn('id', $ids)
            ->select(['id', 'organization_id', 'project_id', 'document_type', 'direction', 'status', 'amount', 'remaining_amount',
                'currency', 'scheduled_at', 'due_date', 'document_date', 'budget_article_id', 'responsibility_center_id',
                'invoiceable_type', 'invoiceable_id', 'source_type', 'source_id', 'contractor_id', 'payee_contractor_id', 'payer_contractor_id'])
            ->selectRaw("jsonb_build_object('contract_id', CASE WHEN length(metadata->>'contract_id') <= 32 THEN metadata->>'contract_id' END) AS metadata")
            ->selectRaw("CASE WHEN length(trim(budget_limit_override_reason)) > 0 THEN 'recorded' ELSE NULL END AS budget_limit_override_reason")
            ->limit(20)->get();
        $checks = [];
        foreach ($documents ?? [] as $document) {
            try {
                $result = $this->budgetLimits->check($document, $actor);
                $excess = ($result['limit']['has_approved_budget'] ?? false) === true ? ($result['summary']['excess_amount'] ?? null) : null;
                $checks[(int) $document->id] = ['status' => $result['status'], 'decision' => $result['decision'],
                    'message' => mb_substr((string) $result['message'], 0, 500),
                    'excess_amount' => self::money($excess) ? $excess : null,
                    'currency' => (string) ($result['limit']['currency'] ?? $document->currency),
                    'override_applied' => ($result['override_applied'] ?? false) === true];
            } catch (\Throwable $exception) {
                Log::error('assistant.payment_budget_read_unavailable', ['organization_id' => $organizationId,
                    'project_id' => $projectId, 'document_id' => (int) $document->id, 'exception_class' => $exception::class]);
                $checks[(int) $document->id] = null;
            }
        }
        return $checks;
    }

    private function pendingApprovals(User $actor, int $organizationId, array $ids): array
    {
        if (! $this->access->canCurrentPermission($actor, $organizationId, 'payments.transaction.view')) { return ['available' => false, 'documents' => []]; }
        $rows = PaymentApproval::query()->where('organization_id', $organizationId)->whereIn('payment_document_id', $ids)->where('status', 'pending')
            ->orderBy('id')->limit(2001)->get(['id', 'payment_document_id', 'approver_user_id']);
        if ($rows->count() > 2000) { return ['available' => false, 'documents' => []]; }
        $members = DB::table('organization_user')->where('organization_id', $organizationId)->where('is_active', true)->select('user_id');
        $names = User::query()->whereIn('id', $rows->pluck('approver_user_id')->filter()->unique()->all())->whereIn('id', $members)->get(['id', 'name'])->keyBy('id');
        $facts = [];
        foreach ($rows->groupBy('payment_document_id') as $id => $group) {
            $examples = []; $unnamed = 0;
            foreach ($group as $row) {
                $name = $names->get($row->approver_user_id)?->name;
                if ($name === null) { $unnamed++; continue; }
                if (count($examples) < 3) { $examples[] = ['id' => (int) $row->approver_user_id, 'name' => $name]; }
            }
            $facts[$id] = ['total' => $group->count(), 'unnamed' => $unnamed, 'examples' => $examples];
        }
        return ['available' => true, 'documents' => $facts];
    }

    private static function money(mixed $value): bool { return is_string($value) && preg_match('/^\d+(?:\.\d{1,2})?$/D', $value) === 1; }
    private static function text(string $value): string
    {
        return str_replace(['*', '_', '[', ']', '`'], ['\\*', '\\_', '\\[', '\\]', '\\`'], htmlspecialchars(mb_substr(preg_replace('/[\p{Cc}\p{Cf}]/u', ' ', $value) ?? '', 0, 255), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }
}
