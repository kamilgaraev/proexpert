<?php

namespace App\Providers;

use App\Models\EstimateItem;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();

        Route::bind('act', function ($value) {
            return \App\Models\ContractPerformanceAct::findOrFail($value);
        });

        // Route Model Binding для estimate, section, item УДАЛЕНЫ
        // Контроллеры теперь сами загружают модели и проверяют организацию

        // Явный binding для item с проверкой организации
        Route::bind('item', function ($value) {
            Log::info('[RouteServiceProvider::bind item] ===== НАЧАЛО РЕЗОЛВИНГА =====', [
                'timestamp' => now()->toIso8601String(),
                'request_id' => uniqid('bind_', true),
            ]);

            Log::info('[RouteServiceProvider::bind item] Начало резолвинга', [
                'value' => $value,
                'value_type' => gettype($value),
                'int_value' => (int) $value,
                'route' => request()->route()?->getName(),
                'url' => request()->fullUrl(),
                'method' => request()->method(),
                'route_params' => request()->route()?->parameters(),
            ]);

            $item = EstimateItem::withTrashed()
                ->where('id', (int) $value)
                ->first();

            Log::info('[RouteServiceProvider::bind item] Результат поиска', [
                'value' => $value,
                'item_found' => $item !== null,
                'item_id' => $item?->id,
                'item_estimate_id' => $item?->estimate_id,
                'item_deleted_at' => $item?->deleted_at,
            ]);

            if (! $item) {
                Log::warning('[RouteServiceProvider::bind item] Элемент не найден', [
                    'value' => $value,
                    'int_value' => (int) $value,
                ]);
                abort(404, 'Позиция сметы не найдена');
            }

            // Загружаем связь estimate (включая удаленные)
            $item->load(['estimate' => function ($query) {
                $query->withTrashed();
            }]);

            Log::info('[RouteServiceProvider::bind item] После загрузки estimate', [
                'item_id' => $item->id,
                'estimate_loaded' => $item->relationLoaded('estimate'),
                'estimate_exists' => $item->estimate !== null,
                'estimate_id' => $item->estimate?->id,
                'estimate_organization_id' => $item->estimate?->organization_id,
                'estimate_deleted_at' => $item->estimate?->deleted_at,
            ]);

            $user = request()->user();
            Log::info('[RouteServiceProvider::bind item] Информация о пользователе', [
                'user_exists' => $user !== null,
                'user_id' => $user?->id,
                'current_organization_id' => $user?->current_organization_id,
            ]);

            if ($user && $user->current_organization_id) {
                // Если estimate не найден, возвращаем 404
                if (! $item->estimate) {
                    Log::warning('[RouteServiceProvider::bind item] Estimate не найден для элемента', [
                        'item_id' => $item->id,
                        'item_estimate_id' => $item->estimate_id,
                    ]);
                    abort(404, 'Смета для этой позиции не найдена');
                }

                // Проверяем организацию
                $itemOrgId = (int) $item->estimate->organization_id;
                $userOrgId = (int) $user->current_organization_id;

                Log::info('[RouteServiceProvider::bind item] Проверка организации', [
                    'item_id' => $item->id,
                    'estimate_id' => $item->estimate->id,
                    'item_organization_id' => $itemOrgId,
                    'user_organization_id' => $userOrgId,
                    'match' => $itemOrgId === $userOrgId,
                ]);

                if ($itemOrgId !== $userOrgId) {
                    Log::warning('[RouteServiceProvider::bind item] Организация не совпадает', [
                        'item_id' => $item->id,
                        'item_organization_id' => $itemOrgId,
                        'user_organization_id' => $userOrgId,
                    ]);
                    abort(403, 'У вас нет доступа к этой позиции сметы');
                }
            }

            Log::info('[RouteServiceProvider::bind item] Успешное резолвинг', [
                'item_id' => $item->id,
                'estimate_id' => $item->estimate?->id,
            ]);

            return $item;
        });

        Route::bind('template', function ($value) {
            $template = \App\Models\EstimateTemplate::findOrFail($value);

            $user = request()->user();
            if ($user && $user->current_organization_id) {
                if (! $template->is_public && $template->organization_id !== $user->current_organization_id) {
                    abort(403, 'У вас нет доступа к этому шаблону сметы');
                }
            }

            return $template;
        });

        Route::bind('version', function ($value) {
            $version = \App\Models\Estimate::findOrFail($value);

            $user = request()->user();
            if ($user && $user->current_organization_id) {
                if ($version->organization_id !== $user->current_organization_id) {
                    abort(403, 'У вас нет доступа к этой версии сметы');
                }
            }

            return $version;
        });

        Route::bind('completed_work', function ($value) {
            $completedWork = \App\Models\CompletedWork::findOrFail($value);

            $user = request()->user();
            if ($user && $user->current_organization_id) {
                if ($completedWork->organization_id !== $user->current_organization_id && ! $this->completedWorkIsAccessibleThroughProjectRoute($completedWork)) {
                    abort(403, 'У вас нет доступа к этой выполненной работе');
                }
            }

            return $completedWork;
        });

        Route::bind('payment', function ($value) {
            $payment = \App\BusinessModules\Core\Payments\Models\PaymentDocument::findOrFail($value);

            $user = request()->user();
            if ($user && $user->current_organization_id) {
                $contract = $payment->invoiceable_type === \App\Models\Contract::class ? $payment->invoiceable : null;
                if ($contract && $contract->organization_id !== $user->current_organization_id) {
                    abort(403, 'У вас нет доступа к этому платежу');
                }
            }

            return $payment;
        });

    }

    /**
     * Configure the rate limiters for the application.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            if ($request->user()) {
                return Limit::perMinute(240)->by('user:'.$request->user()->id);
            }

            return Limit::perMinute(120)->by('ip:'.$request->ip());
        });

        RateLimiter::for('dashboard', function (Request $request) {
            if ($request->user()) {
                return Limit::perMinute(180)->by('user:'.$request->user()->id);
            }

            return Limit::perMinute(60)->by('ip:'.$request->ip());
        });

        RateLimiter::for('public', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });

        RateLimiter::for('legal-editor-callback', function (Request $request) {
            $key = (string) $request->attributes->get('legal_editor_callback_rate_key', 'missing');
            $response = static fn () => new \Illuminate\Http\JsonResponse(['error' => 1], 429);

            return [
                Limit::perMinute(600)->by('legal-editor-callback:global')->response($response),
                Limit::perMinute(30)->by('legal-editor-callback:'.$key)->response($response),
            ];
        });
        RateLimiter::for('legal-signature-callback', static function (Request $request): array {
            $key = hash('sha256', (string) $request->ip().'|'.(string) $request->input('provider'));

            return [Limit::perMinute(300)->by('legal-signature-callback:global'), Limit::perMinute(30)->by($key)];
        });

        RateLimiter::for('auth', function (Request $request) {
            $identity = strtolower(trim((string) $request->input('email', '')));
            $identity = $identity !== '' ? sha1($identity) : 'anonymous';

            return [
                Limit::perMinute(20)->by('ip:'.$request->ip()),
                Limit::perMinute(5)->by('identity:'.$request->ip().'|'.$identity),
            ];
        });
    }

    private function completedWorkIsAccessibleThroughProjectRoute(\App\Models\CompletedWork $completedWork): bool
    {
        $user = request()->user();
        $projectId = request()->route('project');

        if (! $user || ! $user->current_organization_id || ! $projectId) {
            return false;
        }

        if ((int) $completedWork->project_id !== (int) $projectId) {
            return false;
        }

        return \App\Models\Project::query()
            ->where('id', $projectId)
            ->where(function ($query) use ($user) {
                $query->where('organization_id', $user->current_organization_id)
                    ->orWhereHas('organizations', function ($participantQuery) use ($user) {
                        $participantQuery
                            ->where('organizations.id', $user->current_organization_id)
                            ->where('project_organization.is_active', true);
                    });
            })
            ->exists();
    }
}
