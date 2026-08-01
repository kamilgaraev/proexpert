<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Subscriptions;

use App\BusinessModules\Core\Reporting\Application\Access\ReportAccessService;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionClock;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSubscriptionCursorCodec;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSubscriptionStore;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscription;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscriptionCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscriptionPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscriptionWindow;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;

final readonly class ListReportSubscriptionsHandler
{
    public function __construct(
        private ReportSubscriptionStore $store,
        private ReportSubscriptionCursorCodec $cursors,
        private ReportDefinitionRegistry $definitions,
        private ReportAccessService $access,
        private ReportExecutionClock $clock,
    ) {}

    public function handle(ReportExecutionContext $context, ReportSubscriptionWindow $window): ReportSubscriptionPage
    {
        $decoded = $window->cursor === null
            ? null
            : $this->cursors->decode($context, $window->status, $window->cursor);

        $internalCursor = $decoded === null
            ? null
            : ($decoded->lastNextRunAt?->format(DATE_ATOM) ?? 'null').'|'.$decoded->lastId;

        $items = [];
        $lastProcessed = null;
        $hasMore = false;

        while (true) {
            $page = $this->store->list(
                $context->scope->organizationId,
                $context->actor->id,
                new ReportSubscriptionWindow($internalCursor, $window->limit, $window->status),
            );

            foreach ($page->items as $index => $subscription) {
                $lastProcessed = $subscription;
                if ($this->canManage($context, $subscription)) {
                    $items[] = $subscription;
                }

                if (count($items) === $window->limit) {
                    $hasMore = $index < count($page->items) - 1 || $page->hasMore;
                    break 2;
                }
            }

            if (! $page->hasMore) {
                break;
            }

            $internalCursor = $page->nextCursor;
        }

        if (! $hasMore || ! $lastProcessed instanceof ReportSubscription) {
            return new ReportSubscriptionPage($items, null, $window->limit, false);
        }

        return new ReportSubscriptionPage(
            $items,
            $this->cursors->encode($context, new ReportSubscriptionCursor(
                ReportSubscriptionCursor::VERSION,
                $context->scope->organizationId,
                $context->actor->id,
                $window->status,
                ReportSubscriptionCursor::ORDER,
                $lastProcessed->nextRunAt,
                $lastProcessed->id,
                $this->clock->now()->modify('+15 minutes'),
            )),
            $window->limit,
            true,
        );
    }

    private function canManage(ReportExecutionContext $context, ReportSubscription $subscription): bool
    {
        try {
            $definition = $this->definitions->published($subscription->reportCode)->payload();
            $this->access->assertOperation($context, $definition, ReportOperation::MANAGE, null);

            return true;
        } catch (ReportContractException $exception) {
            if ($exception->errorCode === ReportErrorCode::REPORT_NOT_FOUND || $exception->errorCode === ReportErrorCode::REPORT_SCOPE_FORBIDDEN) {
                return false;
            }

            throw $exception;
        }
    }
}
