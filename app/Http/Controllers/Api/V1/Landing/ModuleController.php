<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use App\Http\Responses\LandingResponse;
use App\Models\Module;
use App\Models\OrganizationModuleActivation;
use App\Models\OrganizationSubscription;
use App\Modules\Core\AccessController;
use App\Modules\Core\ModuleManager;
use App\Modules\Services\ModuleActivationService;
use App\Modules\Services\ModuleBillingService;
use App\Modules\Services\ModulePermissionService;
use App\Services\Landing\ModulesOverviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

use function trans_message;

class ModuleController extends Controller
{
    public function __construct(
        private readonly ModuleManager $moduleManager,
        private readonly ModuleActivationService $activationService,
        private readonly ModuleBillingService $billingService,
        private readonly ModulePermissionService $permissionService,
        private readonly ModulesOverviewService $modulesOverviewService
    ) {
    }

    public function overview(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->currentOrganizationId($request);

            if ($organizationId === null) {
                return $this->organizationNotFound();
            }

            return LandingResponse::success($this->modulesOverviewService->build($organizationId));
        } catch (Throwable $exception) {
            return $this->serverError($exception, 'landing_modules.overview_load_error', $request);
        }
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->currentOrganizationId($request);

            if ($organizationId === null) {
                return $this->organizationNotFound();
            }

            $cacheKey = "modules_with_status_{$organizationId}";
            $modulesWithStatus = Cache::remember($cacheKey, 300, function () use ($organizationId) {
                $allModules = $this->moduleManager->getAllAvailableModules();
                $activeModules = $this->moduleManager->getOrganizationModules($organizationId);
                $activeModuleSlugs = $activeModules->pluck('slug')->toArray();
                $moduleIds = Module::query()->whereIn('slug', $activeModuleSlugs)->pluck('id', 'slug');

                $activations = OrganizationModuleActivation::query()
                    ->where('organization_id', $organizationId)
                    ->whereIn('module_id', $moduleIds->values())
                    ->with('module')
                    ->get()
                    ->keyBy(static fn (OrganizationModuleActivation $activation): string => $activation->module->slug);

                return $allModules->map(function (Module $module) use ($activeModuleSlugs, $activations): array {
                    $isActive = ! $module->can_deactivate || in_array($module->slug, $activeModuleSlugs, true);
                    $activation = $isActive && isset($activations[$module->slug]) ? $activations[$module->slug] : null;

                    return [
                        'slug' => $module->slug,
                        'name' => $module->name,
                        'description' => $module->description,
                        'type' => $module->type,
                        'category' => $module->category,
                        'billing_model' => $module->billing_model,
                        'price' => $module->getPrice(),
                        'currency' => $module->getCurrency(),
                        'duration_days' => $module->getDurationDays(),
                        'features' => $module->features ?? [],
                        'permissions' => $module->permissions ?? [],
                        'icon' => $module->icon,
                        'can_deactivate' => $module->can_deactivate,
                        'is_active' => $isActive,
                        'development_status' => $module->getDevelopmentStatusInfo(),
                        'activation' => $activation ? [
                            'activated_at' => $activation->activated_at,
                            'expires_at' => $activation->expires_at,
                            'status' => $activation->status,
                            'days_until_expiration' => $activation->getDaysUntilExpiration(),
                            'is_auto_renew_enabled' => $activation->is_auto_renew_enabled ?? true,
                            'is_bundled_with_plan' => $activation->is_bundled_with_plan ?? false,
                        ] : null,
                    ];
                })->groupBy('category');
            });

            return LandingResponse::success($modulesWithStatus->toArray());
        } catch (Throwable $exception) {
            return $this->serverError($exception, 'landing_modules.load_error', $request);
        }
    }

    public function active(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->currentOrganizationId($request);

            if ($organizationId === null) {
                return $this->organizationNotFound();
            }

            $cacheKey = "active_modules_{$organizationId}";
            $activeModules = Cache::remember(
                $cacheKey,
                300,
                fn () => $this->moduleManager->getOrganizationModules($organizationId)
            );

            return LandingResponse::success(Module::toPublicCollection($activeModules));
        } catch (Throwable $exception) {
            return $this->serverError($exception, 'landing_modules.active_load_error', $request);
        }
    }

    public function activationPreview(Request $request, string $moduleSlug): JsonResponse
    {
        try {
            $organizationId = $this->currentOrganizationId($request);

            if ($organizationId === null) {
                return $this->organizationNotFound();
            }

            $preview = $this->activationService->getActivationPreview($organizationId, $moduleSlug);

            if (! ($preview['success'] ?? false)) {
                return $this->resultError($preview, 'landing_modules.activation_preview_error');
            }

            return LandingResponse::success($this->resultData($preview));
        } catch (Throwable $exception) {
            return $this->serverError($exception, 'landing_modules.activation_preview_error', $request);
        }
    }

    public function activate(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'module_slug' => ['required', 'string'],
                'settings' => ['sometimes', 'array'],
            ]);
            $organizationId = $this->currentOrganizationId($request);

            if ($organizationId === null) {
                return $this->organizationNotFound();
            }

            $result = $this->activationService->activateModule(
                $organizationId,
                (string) $validated['module_slug'],
                ['settings' => $validated['settings'] ?? []]
            );

            if (! ($result['success'] ?? false)) {
                return $this->resultError($result, 'landing_modules.activation_failed');
            }

            app(AccessController::class)->clearAccessCache($organizationId);

            return LandingResponse::success(
                $this->resultData($result),
                trans_message('landing_modules.activation_success')
            );
        } catch (ValidationException $exception) {
            return $this->validationError($exception);
        } catch (Throwable $exception) {
            return $this->serverError($exception, 'landing_modules.activation_failed', $request);
        }
    }

    public function deactivationPreview(Request $request, string $moduleSlug): JsonResponse
    {
        try {
            $organizationId = $this->currentOrganizationId($request);

            if ($organizationId === null) {
                return $this->organizationNotFound();
            }

            $result = $this->moduleManager->deactivationPreview($organizationId, $moduleSlug);

            if (! ($result['success'] ?? false)) {
                return $this->resultError($result, 'landing_modules.deactivation_preview_error');
            }

            return LandingResponse::success($this->resultData($result));
        } catch (Throwable $exception) {
            return $this->serverError($exception, 'landing_modules.deactivation_preview_error', $request);
        }
    }

    public function deactivate(Request $request, string $moduleSlug): JsonResponse
    {
        try {
            $organizationId = $this->currentOrganizationId($request);

            if ($organizationId === null) {
                return $this->organizationNotFound();
            }

            $result = $this->activationService->deactivateModule(
                $organizationId,
                $moduleSlug,
                $request->boolean('with_refund', false)
            );

            if (! ($result['success'] ?? false)) {
                return $this->resultError($result, 'landing_modules.deactivation_failed');
            }

            app(AccessController::class)->clearAccessCache($organizationId);

            return LandingResponse::success(
                $this->resultData($result),
                trans_message('landing_modules.deactivation_success')
            );
        } catch (Throwable $exception) {
            return $this->serverError($exception, 'landing_modules.deactivation_failed', $request);
        }
    }

    public function renew(Request $request, string $moduleSlug): JsonResponse
    {
        try {
            $validated = $request->validate([
                'additional_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            ]);
            $organizationId = $this->currentOrganizationId($request);

            if ($organizationId === null) {
                return $this->organizationNotFound();
            }

            $result = $this->activationService->renewModule(
                $organizationId,
                $moduleSlug,
                (int) ($validated['additional_days'] ?? 30)
            );

            if (! ($result['success'] ?? false)) {
                return $this->resultError($result, 'landing_modules.renew_failed');
            }

            app(AccessController::class)->clearAccessCache($organizationId);

            return LandingResponse::success($this->resultData($result), trans_message('landing_modules.renew_success'));
        } catch (ValidationException $exception) {
            return $this->validationError($exception);
        } catch (Throwable $exception) {
            return $this->serverError($exception, 'landing_modules.renew_failed', $request);
        }
    }

    public function checkAccess(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'module_slug' => ['required', 'string'],
            ]);
            $organizationId = $this->currentOrganizationId($request);

            if ($organizationId === null) {
                return $this->organizationNotFound();
            }

            $moduleSlug = (string) $validated['module_slug'];

            return LandingResponse::success([
                'module_slug' => $moduleSlug,
                'has_access' => $this->moduleManager->hasAccess($organizationId, $moduleSlug),
            ]);
        } catch (ValidationException $exception) {
            return $this->validationError($exception);
        } catch (Throwable $exception) {
            return $this->serverError($exception, 'landing_modules.access_check_error', $request);
        }
    }

    public function expiring(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->currentOrganizationId($request);

            if ($organizationId === null) {
                return $this->organizationNotFound();
            }

            $daysAhead = (int) $request->input('days', 7);
            $cacheKey = "expiring_modules_{$organizationId}_{$daysAhead}";
            $expiringModules = Cache::remember(
                $cacheKey,
                3600,
                fn () => $this->activationService->getExpiringModules($organizationId, $daysAhead)
            );

            return LandingResponse::success($expiringModules);
        } catch (Throwable $exception) {
            return $this->serverError($exception, 'landing_modules.expiring_load_error', $request);
        }
    }

    public function billing(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->currentOrganizationId($request);

            if ($organizationId === null) {
                return $this->organizationNotFound();
            }

            $cacheKey = "module_billing_{$organizationId}";
            $billingData = Cache::remember($cacheKey, 120, function () use ($organizationId): array {
                return [
                    'stats' => $this->billingService->getOrganizationBillingStats($organizationId),
                    'upcoming' => $this->billingService->getUpcomingBilling($organizationId),
                ];
            });

            return LandingResponse::success($billingData);
        } catch (Throwable $exception) {
            return $this->serverError($exception, 'landing_modules.billing_load_error', $request);
        }
    }

    public function billingHistory(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->currentOrganizationId($request);

            if ($organizationId === null) {
                return $this->organizationNotFound();
            }

            return LandingResponse::success(
                $this->billingService->getBillingHistory($organizationId, $request->input('module_slug'))
            );
        } catch (Throwable $exception) {
            return $this->serverError($exception, 'landing_modules.billing_history_load_error', $request);
        }
    }

    public function permissions(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();

            if (! $user) {
                return LandingResponse::error(trans_message('errors.unauthenticated'), Response::HTTP_UNAUTHORIZED);
            }

            $cacheKey = "user_permissions_{$user->id}_{$user->current_organization_id}";
            $permissionsData = Cache::remember($cacheKey, 300, function () use ($user): array {
                return [
                    'permissions' => $this->permissionService->getUserAvailablePermissions($user),
                    'active_modules' => $this->permissionService->getUserActiveModules($user),
                    'permission_matrix' => $this->permissionService->getUserModulePermissionMatrix($user),
                ];
            });

            return LandingResponse::success($permissionsData);
        } catch (Throwable $exception) {
            return $this->serverError($exception, 'landing_modules.permissions_load_error', $request);
        }
    }

    public function bulkActivate(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'module_slugs' => ['required', 'array'],
                'module_slugs.*' => ['string'],
            ]);
            $organizationId = $this->currentOrganizationId($request);

            if ($organizationId === null) {
                return $this->organizationNotFound();
            }

            $result = $this->activationService->bulkActivateModules($organizationId, $validated['module_slugs']);
            app(AccessController::class)->clearAccessCache($organizationId);

            if (! ($result['success'] ?? false)) {
                return $this->resultError($result, 'landing_modules.bulk_activation_failed');
            }

            return LandingResponse::success(
                $this->resultData($result),
                trans_message('landing_modules.bulk_activation_success')
            );
        } catch (ValidationException $exception) {
            return $this->validationError($exception);
        } catch (Throwable $exception) {
            return $this->serverError($exception, 'landing_modules.bulk_activation_failed', $request);
        }
    }

    public function getBundledModules(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->currentOrganizationId($request);

            if ($organizationId === null) {
                return $this->organizationNotFound();
            }

            $subscription = OrganizationSubscription::query()
                ->where('organization_id', $organizationId)
                ->where('status', 'active')
                ->with(['activeBundledModules.module', 'plan'])
                ->first();

            if (! $subscription) {
                return LandingResponse::success(
                    [
                        'bundled_modules' => [],
                        'has_subscription' => false,
                    ],
                    trans_message('landing_modules.no_active_subscription')
                );
            }

            $bundledModules = $subscription->activeBundledModules->map(static function ($activation): array {
                return [
                    'id' => $activation->module->id,
                    'name' => $activation->module->name,
                    'slug' => $activation->module->slug,
                    'description' => $activation->module->description,
                    'category' => $activation->module->category,
                    'icon' => $activation->module->icon,
                    'activated_at' => $activation->activated_at,
                    'expires_at' => $activation->expires_at,
                    'status' => $activation->status,
                ];
            });

            return LandingResponse::success([
                'bundled_modules' => $bundledModules,
                'has_subscription' => true,
                'subscription' => [
                    'plan_name' => $subscription->plan->name,
                    'ends_at' => $subscription->ends_at,
                ],
            ]);
        } catch (Throwable $exception) {
            return $this->serverError($exception, 'landing_modules.bundled_load_error', $request);
        }
    }

    public function activateTrial(Request $request, string $moduleSlug): JsonResponse
    {
        try {
            $organizationId = $this->currentOrganizationId($request);

            if ($organizationId === null) {
                return $this->organizationNotFound();
            }

            $result = $this->moduleManager->activateTrial($organizationId, $moduleSlug);

            if (! ($result['success'] ?? false)) {
                return $this->resultError($result, 'landing_modules.trial_activation_failed');
            }

            app(AccessController::class)->clearAccessCache($organizationId);

            return LandingResponse::success(
                $this->resultData($result),
                trans_message('landing_modules.trial_activation_success')
            );
        } catch (Throwable $exception) {
            return $this->serverError($exception, 'landing_modules.trial_activation_failed', $request);
        }
    }

    public function checkTrialAvailability(Request $request, string $moduleSlug): JsonResponse
    {
        try {
            $organizationId = $this->currentOrganizationId($request);

            if ($organizationId === null) {
                return $this->organizationNotFound();
            }

            $module = Module::query()->where('slug', $moduleSlug)->first();

            if (! $module) {
                return LandingResponse::error(trans_message('landing_modules.module_not_found'), Response::HTTP_NOT_FOUND);
            }

            if ($module->isFree()) {
                return LandingResponse::success(
                    [
                        'trial_available' => false,
                        'reason' => 'FREE_MODULE',
                    ],
                    trans_message('landing_modules.free_module_trial_unavailable')
                );
            }

            $hasUsedTrial = $this->moduleManager->hasUsedTrial($organizationId, $module->id);
            $hasAccess = $this->moduleManager->hasAccess($organizationId, $moduleSlug);
            $pricingConfig = $module->pricing_config ?? [];

            return LandingResponse::success([
                'trial_available' => ! $hasUsedTrial && ! $hasAccess,
                'has_used_trial' => $hasUsedTrial,
                'is_active' => $hasAccess,
                'trial_days' => $pricingConfig['trial_days'] ?? 14,
                'module' => [
                    'name' => $module->name,
                    'slug' => $module->slug,
                    'price' => $module->getPrice(),
                    'currency' => $module->getCurrency(),
                    'billing_model' => $module->billing_model,
                ],
            ]);
        } catch (Throwable $exception) {
            return $this->serverError($exception, 'landing_modules.trial_check_error', $request);
        }
    }

    public function convertTrialToPaid(Request $request, string $moduleSlug): JsonResponse
    {
        try {
            $organizationId = $this->currentOrganizationId($request);

            if ($organizationId === null) {
                return $this->organizationNotFound();
            }

            $result = $this->moduleManager->convertTrialToPaid($organizationId, $moduleSlug);

            if (! ($result['success'] ?? false)) {
                return $this->resultError($result, 'landing_modules.trial_conversion_failed');
            }

            app(AccessController::class)->clearAccessCache($organizationId);

            return LandingResponse::success(
                $this->resultData($result),
                trans_message('landing_modules.trial_conversion_success')
            );
        } catch (Throwable $exception) {
            return $this->serverError($exception, 'landing_modules.trial_conversion_failed', $request);
        }
    }

    public function toggleAutoRenew(Request $request, string $moduleSlug): JsonResponse
    {
        try {
            $validated = $request->validate([
                'enabled' => ['required', 'boolean'],
            ]);
            $organizationId = $this->currentOrganizationId($request);

            if ($organizationId === null) {
                return $this->organizationNotFound();
            }

            $result = $this->moduleManager->toggleAutoRenew($organizationId, $moduleSlug, (bool) $validated['enabled']);

            if (! ($result['success'] ?? false)) {
                return $this->resultError($result, 'landing_modules.auto_renew_failed');
            }

            return LandingResponse::success(
                $this->resultData($result),
                trans_message('landing_modules.auto_renew_success')
            );
        } catch (ValidationException $exception) {
            return $this->validationError($exception);
        } catch (Throwable $exception) {
            return $this->serverError($exception, 'landing_modules.auto_renew_failed', $request);
        }
    }

    public function bulkToggleAutoRenew(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'enabled' => ['required', 'boolean'],
            ]);
            $organizationId = $this->currentOrganizationId($request);

            if ($organizationId === null) {
                return $this->organizationNotFound();
            }

            $result = $this->moduleManager->bulkToggleAutoRenew($organizationId, (bool) $validated['enabled']);

            if (! ($result['success'] ?? false)) {
                return $this->resultError($result, 'landing_modules.auto_renew_bulk_failed');
            }

            return LandingResponse::success(
                $this->resultData($result),
                trans_message('landing_modules.auto_renew_bulk_success')
            );
        } catch (ValidationException $exception) {
            return $this->validationError($exception);
        } catch (Throwable $exception) {
            return $this->serverError($exception, 'landing_modules.auto_renew_bulk_failed', $request);
        }
    }

    private function currentOrganizationId(Request $request): ?int
    {
        $organizationId = $request->attributes->get('current_organization_id')
            ?? $request->user()?->current_organization_id;

        return $organizationId ? (int) $organizationId : null;
    }

    private function organizationNotFound(): JsonResponse
    {
        return LandingResponse::error(
            trans_message('landing_modules.organization_not_found'),
            Response::HTTP_NOT_FOUND
        );
    }

    private function validationError(ValidationException $exception): JsonResponse
    {
        return LandingResponse::error(
            trans_message('errors.validation_failed'),
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $exception->errors()
        );
    }

    private function resultError(array $result, string $defaultMessageKey): JsonResponse
    {
        $code = isset($result['code']) && is_scalar($result['code']) ? (string) $result['code'] : null;
        $messageKey = $code ? 'landing_modules.codes.' . $code : $defaultMessageKey;

        return LandingResponse::error(
            trans_message($messageKey) === $messageKey ? trans_message($defaultMessageKey) : trans_message($messageKey),
            $this->statusFromResultCode($code),
            null,
            $code ? ['code' => $code] : []
        );
    }

    private function resultData(array $result): array
    {
        unset($result['success'], $result['message']);

        return $result;
    }

    private function statusFromResultCode(?string $code): int
    {
        return match ($code) {
            'MODULE_NOT_FOUND' => Response::HTTP_NOT_FOUND,
            'TRIAL_ALREADY_USED',
            'MODULE_ALREADY_ACTIVE',
            'MISSING_DEPENDENCIES',
            'MODULE_CONFLICTS' => Response::HTTP_CONFLICT,
            'INSUFFICIENT_BALANCE' => Response::HTTP_PAYMENT_REQUIRED,
            default => Response::HTTP_BAD_REQUEST,
        };
    }

    private function serverError(Throwable $exception, string $messageKey, Request $request): JsonResponse
    {
        Log::error('Landing module request failed', [
            'message_key' => $messageKey,
            'user_id' => $request->user()?->id,
            'organization_id' => $request->attributes->get('current_organization_id'),
            'exception' => $exception,
        ]);

        return LandingResponse::error(trans_message($messageKey), Response::HTTP_INTERNAL_SERVER_ERROR);
    }
}
