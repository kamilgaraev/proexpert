<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Landing\Billing;

use App\Exceptions\Billing\InsufficientBalanceException;
use App\Http\Controllers\Controller;
use App\Http\Responses\LandingResponse;
use App\Models\OrganizationSubscription;
use App\Repositories\Landing\OrganizationSubscriptionRepository;
use App\Services\Landing\OrganizationSubscriptionService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

use function trans_message;

class OrganizationSubscriptionController extends Controller
{
    public function __construct(
        protected OrganizationSubscriptionService $subscriptionService,
        protected OrganizationSubscriptionRepository $subscriptionRepository
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->currentOrganizationId($request);

            if (! $organizationId) {
                return $this->organizationNotFoundResponse();
            }

            $subscription = $this->subscriptionService->getCurrentSubscription($organizationId);

            if (! $subscription || ! $subscription->isActive()) {
                return LandingResponse::success([
                    'has_subscription' => false,
                    'subscription' => null,
                ], trans_message('billing.subscription.not_active'));
            }

            return LandingResponse::success([
                'has_subscription' => true,
                'subscription' => $this->formatSubscription($subscription),
            ], trans_message('billing.subscription.loaded'));
        } catch (Throwable $exception) {
            $this->logFailure('landing.billing.subscription.load_failed', $exception, $request);

            return LandingResponse::error(
                trans_message('billing.subscription.load_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan_slug' => ['required', 'string'],
            'duration_days' => ['nullable', 'integer', 'in:30,90,365'],
            'is_auto_payment_enabled' => ['nullable', 'boolean'],
        ]);

        try {
            $organizationId = $this->currentOrganizationId($request);

            if (! $organizationId) {
                return $this->organizationNotFoundResponse();
            }

            $subscription = $this->subscriptionService->subscribe(
                $organizationId,
                $data['plan_slug'],
                $request->boolean('is_auto_payment_enabled', true),
                (int) ($data['duration_days'] ?? 30)
            );

            return LandingResponse::success(
                $this->formatSubscription($subscription),
                trans_message('billing.subscription.created'),
                Response::HTTP_CREATED
            );
        } catch (InsufficientBalanceException) {
            return LandingResponse::error(
                trans_message('billing.subscription.insufficient_balance'),
                Response::HTTP_PAYMENT_REQUIRED
            );
        } catch (ModelNotFoundException) {
            return LandingResponse::error(
                trans_message('billing.subscription.plan_not_found'),
                Response::HTTP_NOT_FOUND
            );
        } catch (Throwable $exception) {
            $this->logFailure('landing.billing.subscription.create_failed', $exception, $request);

            return LandingResponse::error(
                trans_message('billing.subscription.create_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan_slug' => ['required', 'string'],
            'is_auto_payment_enabled' => ['nullable', 'boolean'],
        ]);

        try {
            $organizationId = $this->currentOrganizationId($request);

            if (! $organizationId) {
                return $this->organizationNotFoundResponse();
            }

            $subscription = $this->subscriptionService->updateSubscription(
                $organizationId,
                $data['plan_slug'],
                $request->boolean('is_auto_payment_enabled', true)
            );

            return LandingResponse::success(
                $this->formatSubscription($subscription),
                trans_message('billing.subscription.updated')
            );
        } catch (InsufficientBalanceException) {
            return LandingResponse::error(
                trans_message('billing.subscription.insufficient_balance'),
                Response::HTTP_PAYMENT_REQUIRED
            );
        } catch (ModelNotFoundException) {
            return LandingResponse::error(
                trans_message('billing.subscription.plan_not_found'),
                Response::HTTP_NOT_FOUND
            );
        } catch (Throwable $exception) {
            $this->logFailure('landing.billing.subscription.update_failed', $exception, $request);

            return LandingResponse::error(
                trans_message('billing.subscription.update_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    public function cancel(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->currentOrganizationId($request);

            if (! $organizationId) {
                return $this->organizationNotFoundResponse();
            }

            $result = $this->subscriptionService->cancelSubscription($organizationId);

            if (! ($result['success'] ?? false)) {
                return $this->subscriptionResultError($result, 'billing.subscription.cancel_error');
            }

            $subscription = $result['subscription'];

            return LandingResponse::success(
                $this->formatSubscription($subscription),
                trans_message('billing.subscription.cancelled_until', [
                    'date' => $subscription->ends_at?->format('d.m.Y'),
                ])
            );
        } catch (Throwable $exception) {
            $this->logFailure('landing.billing.subscription.cancel_failed', $exception, $request);

            return LandingResponse::error(
                trans_message('billing.subscription.cancel_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    public function changePlanPreview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan_slug' => ['required', 'string', 'exists:subscription_plans,slug'],
        ]);

        try {
            $organizationId = $this->currentOrganizationId($request);

            if (! $organizationId) {
                return $this->organizationNotFoundResponse();
            }

            $result = $this->subscriptionService->previewPlanChange($organizationId, $data['plan_slug']);

            if (! ($result['success'] ?? false)) {
                return $this->subscriptionResultError($result, 'billing.subscription.change_preview_error');
            }

            return LandingResponse::success(
                $result['preview'],
                trans_message('billing.subscription.change_preview_ready')
            );
        } catch (Throwable $exception) {
            $this->logFailure('landing.billing.subscription.change_preview_failed', $exception, $request);

            return LandingResponse::error(
                trans_message('billing.subscription.change_preview_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    public function changePlan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan_slug' => ['required', 'string', 'exists:subscription_plans,slug'],
        ]);

        try {
            $organizationId = $this->currentOrganizationId($request);

            if (! $organizationId) {
                return $this->organizationNotFoundResponse();
            }

            $result = $this->subscriptionService->changePlan($organizationId, $data['plan_slug']);

            if (! ($result['success'] ?? false)) {
                return $this->subscriptionResultError($result, 'billing.subscription.change_error');
            }

            return LandingResponse::success([
                'subscription' => $this->formatSubscription($result['subscription']),
                'billing_info' => $result['billing_info'] ?? null,
            ], trans_message('billing.subscription.change_success'));
        } catch (InsufficientBalanceException) {
            return LandingResponse::error(
                trans_message('billing.subscription.insufficient_balance'),
                Response::HTTP_PAYMENT_REQUIRED
            );
        } catch (Throwable $exception) {
            $this->logFailure('landing.billing.subscription.change_failed', $exception, $request);

            return LandingResponse::error(
                trans_message('billing.subscription.change_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    public function updateAutoPayment(Request $request): JsonResponse
    {
        $data = $request->validate([
            'is_auto_payment_enabled' => ['required', 'boolean'],
        ]);

        try {
            $organizationId = $this->currentOrganizationId($request);

            if (! $organizationId) {
                return $this->organizationNotFoundResponse();
            }

            $subscription = $this->subscriptionRepository->getByOrganizationId($organizationId);

            if (! $subscription) {
                return LandingResponse::error(
                    trans_message('billing.subscription.not_found'),
                    Response::HTTP_NOT_FOUND
                );
            }

            $subscription->update(['is_auto_payment_enabled' => (bool) $data['is_auto_payment_enabled']]);

            return LandingResponse::success(
                $this->formatSubscription($subscription->fresh() ?? $subscription),
                trans_message('billing.subscription.auto_payment_updated')
            );
        } catch (Throwable $exception) {
            $this->logFailure('landing.billing.subscription.auto_payment_update_failed', $exception, $request);

            return LandingResponse::error(
                trans_message('billing.subscription.auto_payment_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    private function currentOrganizationId(Request $request): ?int
    {
        $user = $request->user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user?->current_organization_id;

        if (! $user || ! $organizationId) {
            return null;
        }

        if ((int) $organizationId !== (int) $user->current_organization_id) {
            return null;
        }

        return (int) $organizationId;
    }

    private function organizationNotFoundResponse(): JsonResponse
    {
        return LandingResponse::error(
            trans_message('billing.subscription.organization_not_found'),
            Response::HTTP_NOT_FOUND
        );
    }

    private function subscriptionResultError(array $result, string $fallbackKey): JsonResponse
    {
        $statusCode = (int) ($result['status_code'] ?? Response::HTTP_UNPROCESSABLE_ENTITY);
        $messageKey = match ($statusCode) {
            Response::HTTP_NOT_FOUND => 'billing.subscription.not_found',
            Response::HTTP_BAD_REQUEST => 'billing.subscription.action_not_available',
            default => $fallbackKey,
        };

        $extra = [];

        if (array_key_exists('billing_info', $result)) {
            $extra['billing_info'] = $result['billing_info'];
        }

        return LandingResponse::error(
            trans_message($messageKey),
            $statusCode,
            null,
            $extra
        );
    }

    private function formatSubscription(OrganizationSubscription $subscription): array
    {
        $subscription->loadMissing(['plan', 'activeBundledModules.module', 'activeBundledPackages']);
        $plan = $subscription->plan;

        $bundledModules = $subscription->activeBundledModules
            ->filter(fn ($activation): bool => $activation->module !== null)
            ->map(fn ($activation): array => [
                'id' => $activation->module->id,
                'name' => $activation->module->name,
                'slug' => $activation->module->slug,
                'category' => $activation->module->category,
                'icon' => $activation->module->icon,
                'activated_at' => $activation->activated_at?->format('Y-m-d H:i:s'),
                'expires_at' => $activation->expires_at?->format('Y-m-d H:i:s'),
            ])
            ->values();

        $includedPackages = $subscription->activeBundledPackages
            ->map(function ($packageSubscription): array {
                $configPath = config_path('Packages/' . $packageSubscription->package_slug . '.json');
                $config = file_exists($configPath)
                    ? json_decode((string) file_get_contents($configPath), true)
                    : [];
                $tier = $config['tiers'][$packageSubscription->tier] ?? [];

                return [
                    'package_slug' => $packageSubscription->package_slug,
                    'tier' => $packageSubscription->tier,
                    'name' => $config['name'] ?? $packageSubscription->package_slug,
                    'tier_label' => $tier['label'] ?? $packageSubscription->tier,
                    'description' => $config['description'] ?? null,
                    'icon' => $config['icon'] ?? null,
                    'color' => $config['color'] ?? null,
                    'modules' => $tier['modules'] ?? [],
                    'expires_at' => $packageSubscription->expires_at?->format('Y-m-d H:i:s'),
                ];
            })
            ->values();

        return [
            'id' => $subscription->id,
            'status' => $subscription->status,
            'plan' => [
                'slug' => $plan?->slug,
                'name' => $plan?->name,
                'description' => $plan?->description ?? '',
                'price' => $plan?->price ?? 0,
                'currency' => $plan?->currency ?? 'RUB',
                'features' => $plan?->features ?? [],
                'included_packages' => $plan?->included_packages ?? [],
            ],
            'is_trial' => false,
            'trial_ends_at' => $subscription->trial_ends_at?->format('Y-m-d H:i:s'),
            'ends_at' => $subscription->ends_at?->format('Y-m-d H:i:s'),
            'next_billing_at' => $subscription->next_billing_at?->format('Y-m-d H:i:s'),
            'is_canceled' => $subscription->canceled_at !== null,
            'canceled_at' => $subscription->canceled_at?->format('Y-m-d H:i:s'),
            'is_auto_payment_enabled' => $subscription->is_auto_payment_enabled,
            'included_packages' => $includedPackages,
            'included_packages_count' => $includedPackages->count(),
            'bundled_modules' => $bundledModules,
            'bundled_modules_count' => $bundledModules->count(),
        ];
    }

    private function logFailure(string $event, Throwable $exception, Request $request): void
    {
        Log::error($event, [
            'user_id' => $request->user()?->id,
            'organization_id' => $request->attributes->get('current_organization_id'),
            'exception_class' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }
}
