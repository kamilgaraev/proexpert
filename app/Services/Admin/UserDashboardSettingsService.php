<?php

namespace App\Services\Admin;

use App\Models\UserDashboardSetting;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class UserDashboardSettingsService
{
    public function __construct(private DashboardWidgetsRegistry $registry)
    {
    }

    public function getMergedForCurrentUser(?int $organizationId = null): ?array
    {
        /** @var User $user */
        /** @var User $user */
        $user = Auth::user();
        $orgId = $organizationId ?? (int)($user->current_organization_id ?? 0);

        $roles = method_exists($user, 'roles') ? $user->roles()->where('role_user.organization_id', $orgId)->pluck('slug')->all() : [];
        $registry = $this->registry->get($roles);

        $existing = UserDashboardSetting::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $orgId)
            ->first();

        if (!$existing) {
            return null;
        }

        $merged = $this->mergeSettings($existing->toArray(), $registry);

        if ($merged['version'] !== $existing->version || $merged['items'] !== $existing->items) {
            $existing->version = $merged['version'];
            $existing->items = $merged['items'];
            $existing->layout_mode = $merged['layout_mode'] ?? ($existing->layout_mode ?? 'simple');
            $existing->save();
        }

        return $merged;
    }

    public function saveForCurrentUser(array $payload, ?int $organizationId = null): array
    {
        /** @var User $user */
        $user = Auth::user();
        $orgId = $organizationId ?? (int)($user->current_organization_id ?? 0);

        $roles = method_exists($user, 'roles') ? $user->roles()->where('role_user.organization_id', $orgId)->pluck('slug')->all() : [];
        $registry = $this->registry->get($roles);

        $this->validateAgainstRegistry($payload, $registry);

        return DB::transaction(function () use ($user, $orgId, $payload) {
            $model = UserDashboardSetting::query()
                ->updateOrCreate(
                    ['user_id' => $user->id, 'organization_id' => $orgId],
                    [
                        'version' => $payload['version'],
                        'layout_mode' => $payload['layout_mode'] ?? 'simple',
                        'items' => $payload['items'],
                    ]
                );

            return $model->toArray();
        });
    }

    public function resetForCurrentUser(?int $organizationId = null): void
    {
        /** @var User $user */
        $user = Auth::user();
        $orgId = $organizationId ?? (int)($user->current_organization_id ?? 0);

        UserDashboardSetting::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $orgId)
            ->delete();
    }

    public function defaultsForCurrentUser(?int $organizationId = null): array
    {
        /** @var User $user */
        $user = Auth::user();
        $orgId = $organizationId ?? (int)($user->current_organization_id ?? 0);
        $roles = method_exists($user, 'roles') ? $user->roles()->where('role_user.organization_id', $orgId)->pluck('slug')->all() : [];
        $registry = $this->registry->get($roles);
        return $this->buildDefaultsFromRegistry($registry);
    }

    private function buildDefaultsFromRegistry(array $registry): array
    {
        $items = [];
        $order = 10;
        foreach ($registry['widgets'] as $w) {
            $items[] = [
                'id' => $w['id'],
                'enabled' => (bool)($w['default_enabled'] ?? true),
                'order' => $order,
                'size' => $w['default_size'] ?? null,
            ];
            $order += 10;
        }
        return [
            'version' => $registry['version'],
            'layout_mode' => 'simple',
            'items' => $items,
            'updated_at' => now()->toISOString(),
        ];
    }

    private function mergeSettings(array $settings, array $registry): array
    {
        $byId = [];
        foreach ($settings['items'] as $it) {
            $byId[$it['id']] = $it;
        }

        $mergedItems = [];
        $order = 10;
        foreach ($registry['widgets'] as $w) {
            $existing = $byId[$w['id']] ?? null;
            if ($existing) {
                $existing['order'] = $existing['order'] ?? $order;
                if (!empty($existing['size']) && !empty($w['min_size'])) {
                    foreach (['xs','md','lg'] as $bp) {
                        if (isset($existing['size'][$bp], $w['min_size'][$bp]) && $existing['size'][$bp] < $w['min_size'][$bp]) {
                            $existing['size'][$bp] = $w['min_size'][$bp];
                        }
                    }
                }
                $mergedItems[] = $existing;
            } else {
                $mergedItems[] = [
                    'id' => $w['id'],
                    'enabled' => (bool)($w['default_enabled'] ?? true),
                    'order' => $order,
                    'size' => $w['default_size'] ?? null,
                ];
            }
            $order += 10;
        }

        usort($mergedItems, fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

        return [
            'version' => $registry['version'],
            'layout_mode' => $settings['layout_mode'] ?? 'simple',
            'items' => $mergedItems,
            'updated_at' => now()->toISOString(),
        ];
    }

    private function validateAgainstRegistry(array $payload, array $registry): void
    {
        $ids = array_map(fn($w) => $w['id'], $registry['widgets']);
        $minSizeById = [];
        foreach ($registry['widgets'] as $w) {
            if (!empty($w['min_size'])) {
                $minSizeById[$w['id']] = $w['min_size'];
            }
        }

        $orders = [];
        foreach ($payload['items'] as $item) {
            if (!in_array($item['id'], $ids, true)) {
                abort(422, 'unknown_widget_id');
            }
            if (in_array($item['order'], $orders, true)) {
                abort(422, 'duplicate_order');
            }
            $orders[] = $item['order'];

            if (!empty($item['size']) && !empty($minSizeById[$item['id']])) {
                foreach (['xs','md','lg'] as $bp) {
                    if (isset($item['size'][$bp], $minSizeById[$item['id']][$bp]) && $item['size'][$bp] < $minSizeById[$item['id']][$bp]) {
                        abort(422, 'size_too_small');
                    }
                }
            }
        }

        if ((int)$payload['version'] !== (int)$registry['version']) {
            abort(409, 'invalid_version');
        }
    }
}


