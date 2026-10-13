<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\Models\User;
use App\Services\Entitlements\OrganizationEntitlementService;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class AssistantOrganizationPurchaseOrdersReader extends AssistantOrganizationOperationalReader
{
    public const TYPE = 'live_organization_purchase_order_queue_projection';

    public static function hasIntent(string $query): bool
    {
        return preg_match('/заказ[а-яё]*\s+поставщик|закупочн[а-яё]*\s+заказ/iu', $query) === 1;
    }

    public static function matches(string $query): bool
    {
        return self::hasIntent($query) && preg_match('/организац/iu', $query) === 1
            && preg_match('/создай|удали|отправь|измени|получи\s+заказ|оплат|сч[её]т|бюджет|смет|стоим|сумм|проект|объект|сотрудник|уведомлен/iu', $query) !== 1;
    }

    protected function collect(User $actor, int $organizationId): array
    {
        $modules = app(OrganizationEntitlementService::class)->getEffectiveModuleSlugs($organizationId);
        if (! in_array('procurement', $modules, true) || ! in_array('basic-warehouse', $modules, true)
            || ! $this->access->canCurrentPermission($actor, $organizationId, 'procurement.purchase_orders.view')) {
            throw new AuthorizationException;
        }
        $query = PurchaseOrder::query()->forOrganization($organizationId);
        $counts = (clone $query)->select('status')->selectRaw('COUNT(*) AS total')->groupBy('status')->get()
            ->mapWithKeys(static fn ($row): array => [(string) $row->getRawOriginal('status') => (int) $row->getAttribute('total')])->all();
        $open = (clone $query)->whereNotIn('status', ['delivered', 'cancelled']);
        $examples = (clone $open)->orderByRaw('delivery_date IS NULL')->orderBy('delivery_date')->orderBy('id')->limit(20)
            ->select(['id', 'order_number', 'status', 'delivery_date'])->get()->map(static fn ($row): array => ['id' => (int) $row->id,
                'number' => $row->order_number, 'status' => $row->getRawOriginal('status'), 'date' => $row->delivery_date?->toDateString()])->all();

        return ['total' => array_sum($counts), 'open' => (clone $open)->count(), 'counts' => $counts,
            'overdue' => (clone $open)->whereDate('delivery_date', '<', today()->toDateString())->count(), 'examples' => $examples];
    }

    protected function format(array $data): string
    {
        $lines = [trans_message('ai_assistant_operational.orders_scope', ['date' => $data['as_of_date']]),
            trans_message('ai_assistant_operational.orders_counts', ['total' => $data['total'], 'open' => $data['open'], 'overdue' => $data['overdue']])];
        foreach ($data['counts'] as $status => $count) {
            $lines[] = trans_message('ai_assistant_operational.orders_status_'.$status).': '.$count;
        }
        foreach ($data['examples'] as $row) {
            $lines[] = '- #'.$row['id'].' '.self::text($row['number']).'; '.trans_message('ai_assistant_operational.orders_status_'.$row['status']).'; '
                .trans_message('ai_assistant_operational.orders_date', ['date' => $row['date'] ?? trans_message('ai_assistant_operational.not_recorded')]);
        }
        $lines[] = trans_message('ai_assistant_operational.orders_basis');
        $lines[] = trans_message('ai_assistant_operational.examples_basis');

        return implode("\n", $lines);
    }
}
