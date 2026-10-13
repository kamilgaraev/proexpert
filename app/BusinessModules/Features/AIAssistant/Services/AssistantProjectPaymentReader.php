<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Models\PaymentApproval;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final readonly class AssistantProjectPaymentReader
{
    public const TYPE = 'live_project_payment_readiness_projection';
    public function __construct(private AssistantDataAccessPolicy $access) {}

    public static function matches(string $query): bool
    {
        return preg_match('/сч[её]т[а-яё]*/iu', $query) === 1 && preg_match('/не\s+оплачен|неоплачен|перед\s+оплатой|жд[её]т.*оплат/iu', $query) === 1
            && preg_match('/создай|оплати|одобри|утверди|удали|бюджет|потрач|расход|затрат|прибыл|выручк|график|задач|табел|смет|поставк|замечан|сотрудник|техник|машин|механизм|бим|bim|ifc/iu', $query) !== 1;
    }

    public function read(User $actor, int $organizationId, array $arguments): array
    {
        $projectId = $arguments['project_id'] ?? null;
        if (! is_int($projectId) || $projectId < 1 || array_keys($arguments) !== ['project_id']) { throw new \InvalidArgumentException('invalid_payment_readiness_scope'); }
        return $this->access->withCurrentChecks($actor, $organizationId, function () use ($actor, $organizationId, $projectId): array {
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
            $window = array_slice($items, 0, 20);
            $approvals = $this->pendingApprovals($actor, $organizationId, array_column($window, 'id'));
            foreach ($window as &$item) { $item['approvals'] = $approvals['documents'][$item['id']] ?? ['total' => $approvals['available'] ? 0 : null, 'unnamed' => $approvals['available'] ? 0 : null, 'examples' => []]; }
            unset($item);
            $payload = ['project' => ['id' => $projectId, 'name' => $project->name], 'as_of_date' => today()->toDateString(), 'complete' => count($rows) <= 10000,
                'total' => count($rows) <= 10000 ? count($items) : null, 'unknown_balance_count' => count($rows) <= 10000 ? $unknown : null, 'items' => $window,
                'overdue_outgoing' => count($rows) <= 10000 ? count(array_filter($items, static fn (array $item): bool => $item['is_overdue'] && $item['direction'] === 'outgoing')) : null,
                'overdue_incoming' => count($rows) <= 10000 ? count(array_filter($items, static fn (array $item): bool => $item['is_overdue'] && $item['direction'] === 'incoming')) : null,
                'approval_read_available' => $approvals['available']];
            $version = AssistantSourceReferenceIdentity::key(['schema' => 1, 'organization_id' => $organizationId, 'actor_id' => (int) $actor->id, 'payload' => $payload, 'rows' => $rows]);
            $ref = ['entity_type' => self::TYPE, 'entity_id' => $projectId, 'project_id' => $projectId, 'organization_id' => $organizationId,
                'actor_id' => (int) $actor->id, 'source_version' => $version, 'fetched_at' => now()->toIso8601String()];
            return ['status' => 'partial', 'source_refs' => [$ref], 'data' => $payload, 'server_formatted_answer' => self::format($payload)];
        }, true);
    }

    public function matchesReference(User $actor, int $organizationId, array $ref): bool
    {
        if (($ref['entity_type'] ?? null) !== self::TYPE || ($ref['organization_id'] ?? null) !== $organizationId || ($ref['actor_id'] ?? null) !== (int) $actor->id
            || ($ref['project_id'] ?? null) !== ($ref['entity_id'] ?? null) || ! is_int($ref['entity_id'] ?? null) || ! is_string($ref['source_version'] ?? null)) { return false; }
        try { return $this->read($actor, $organizationId, ['project_id' => $ref['entity_id']])['source_refs'][0]['source_version'] === $ref['source_version']; }
        catch (\Throwable) { return false; }
    }

    public function verifiedAnswer(array $result, User $actor, int $organizationId): ?array
    {
        $ref = $result['source_refs'][0] ?? null;
        if (! is_array($ref) || ! $this->matchesReference($actor, $organizationId, $ref)) { return null; }
        $current = $this->read($actor, $organizationId, ['project_id' => $ref['entity_id']]);
        if ($current['source_refs'][0]['source_version'] !== $ref['source_version']) { return null; }
        return ['text' => $current['server_formatted_answer'], 'source_refs' => $current['source_refs'], 'validation_status' => 'partial', 'replaced' => true, 'needs_clarification' => false];
    }

    private static function format(array $data): string
    {
        $lines = [trans_message('ai_assistant_decisions.payment_scope', ['project' => self::text($data['project']['name']), 'date' => $data['as_of_date']])];
        if (! $data['complete']) { $lines[] = trans_message('ai_assistant_decisions.payment_limit'); return implode("\n", $lines); }
        $lines[] = trans_message('ai_assistant_decisions.payment_count', ['total' => $data['total'], 'shown' => count($data['items']), 'unknown' => $data['unknown_balance_count']]);
        $lines[] = trans_message('ai_assistant_decisions.payment_overdue_counts', ['outgoing' => $data['overdue_outgoing'], 'incoming' => $data['overdue_incoming']]);
        foreach ($data['items'] as $item) {
            $lines[] = '- '.self::text((string) $item['number']).' (#'.$item['id'].'): '.trans_message('ai_assistant_decisions.payment_amounts',
                ['amount' => $item['amount'], 'paid' => $item['paid_amount'], 'remaining' => $item['remaining_amount'], 'currency' => self::text((string) $item['currency'])]);
            $lines[] = self::text((string) $item['direction_label']).'; '.self::text((string) $item['status_label']).'. '.trans_message('ai_assistant_decisions.payment_due', ['date' => $item['due_date'] ?? '—']);
            if ($item['is_overdue']) { $lines[] = trans_message('ai_assistant_decisions.payment_overdue'); }
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
