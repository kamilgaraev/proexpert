<?php

declare(strict_types=1);

namespace App\Services\Filament;

use App\BusinessModules\Core\Payments\Enums\PaymentTransactionStatus;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use App\Enums\Activity\ActivitySeverityEnum;
use App\Enums\Blog\BlogArticleStatusEnum;
use App\Models\Activity\ActivityEvent;
use App\Models\Blog\BlogArticle;
use App\Models\ContactForm;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class SystemAdminDashboardService
{
    /**
     * @return array{
     *     organizations: array{active: int, trial: int, paying: int},
     *     subscriptions: array{overdue: int},
     *     payments: array{failed_30_days: int},
     *     users: array{new_7_days: int, new_30_days: int},
     *     blog: array{draft: int, published: int, scheduled: int, archived: int},
     *     support: array{pending: int, urgent: int},
     *     audit: array{high_risk_24_hours: int}
     * }
     */
    public function overview(?CarbonInterface $now = null): array
    {
        $now = $this->immutableNow($now);

        return [
            'organizations' => [
                'active' => $this->activeOrganizations(),
                'trial' => $this->trialOrganizations($now),
                'paying' => $this->payingOrganizations($now),
            ],
            'subscriptions' => [
                'overdue' => $this->overdueSubscriptions($now),
            ],
            'payments' => [
                'failed_30_days' => $this->failedPayments($now),
            ],
            'users' => [
                'new_7_days' => $this->newUsers($now->subDays(7)),
                'new_30_days' => $this->newUsers($now->subDays(30)),
            ],
            'blog' => $this->blogArticlesByStatus(),
            'support' => [
                'pending' => $this->pendingSupportRequests(),
                'urgent' => $this->urgentSupportRequests(),
            ],
            'audit' => [
                'high_risk_24_hours' => $this->highRiskAuditEvents($now),
            ],
        ];
    }

    private function activeOrganizations(): int
    {
        return Organization::query()
            ->where('is_active', true)
            ->count();
    }

    private function trialOrganizations(CarbonImmutable $now): int
    {
        return OrganizationSubscription::query()
            ->where('status', 'trial')
            ->where(function ($query) use ($now): void {
                $query->whereNull('trial_ends_at')
                    ->orWhere('trial_ends_at', '>', $now);
            })
            ->distinct('organization_id')
            ->count('organization_id');
    }

    private function payingOrganizations(CarbonImmutable $now): int
    {
        return OrganizationSubscription::query()
            ->where('status', 'active')
            ->whereNull('canceled_at')
            ->where('ends_at', '>', $now)
            ->distinct('organization_id')
            ->count('organization_id');
    }

    private function overdueSubscriptions(CarbonImmutable $now): int
    {
        return OrganizationSubscription::query()
            ->whereNull('canceled_at')
            ->whereNotNull('ends_at')
            ->where('ends_at', '<', $now)
            ->whereIn('status', ['active', 'trial', 'pending_payment', 'failed'])
            ->count();
    }

    private function failedPayments(CarbonImmutable $now): int
    {
        return PaymentTransaction::query()
            ->where('status', PaymentTransactionStatus::FAILED->value)
            ->where('created_at', '>=', $now->subDays(30))
            ->count();
    }

    private function newUsers(CarbonImmutable $from): int
    {
        return User::query()
            ->where('created_at', '>=', $from)
            ->count();
    }

    /**
     * @return array{draft: int, published: int, scheduled: int, archived: int}
     */
    private function blogArticlesByStatus(): array
    {
        $counts = BlogArticle::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            BlogArticleStatusEnum::DRAFT->value => (int) ($counts[BlogArticleStatusEnum::DRAFT->value] ?? 0),
            BlogArticleStatusEnum::PUBLISHED->value => (int) ($counts[BlogArticleStatusEnum::PUBLISHED->value] ?? 0),
            BlogArticleStatusEnum::SCHEDULED->value => (int) ($counts[BlogArticleStatusEnum::SCHEDULED->value] ?? 0),
            BlogArticleStatusEnum::ARCHIVED->value => (int) ($counts[BlogArticleStatusEnum::ARCHIVED->value] ?? 0),
        ];
    }

    private function pendingSupportRequests(): int
    {
        return ContactForm::query()
            ->whereIn('status', [ContactForm::STATUS_NEW, ContactForm::STATUS_PROCESSING])
            ->count();
    }

    private function urgentSupportRequests(): int
    {
        return ContactForm::query()
            ->whereIn('status', [ContactForm::STATUS_NEW, ContactForm::STATUS_PROCESSING])
            ->where('priority', ContactForm::PRIORITY_URGENT)
            ->count();
    }

    private function highRiskAuditEvents(CarbonImmutable $now): int
    {
        return ActivityEvent::query()
            ->whereIn('severity', [
                ActivitySeverityEnum::Warning->value,
                ActivitySeverityEnum::Critical->value,
            ])
            ->where('occurred_at', '>=', $now->subDay())
            ->count();
    }

    private function immutableNow(?CarbonInterface $now): CarbonImmutable
    {
        if ($now instanceof CarbonImmutable) {
            return $now;
        }

        if ($now instanceof CarbonInterface) {
            return CarbonImmutable::instance($now);
        }

        return CarbonImmutable::now();
    }
}
