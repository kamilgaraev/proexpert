<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Services;

use App\BusinessModules\Features\DesignManagement\Events\DesignModelSessionTransientEvent;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelSession;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelSessionEventOrder;
use App\Models\User;
use Illuminate\Cache\Repository;
use Illuminate\Cache\RedisStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final readonly class DesignModelSessionStateService
{
    public const TTL = 45;

    public const HEARTBEAT_INTERVAL = 15;

    public function __construct(private DesignModelSessionAccessService $access) {}

    public static function sender(User $user): array
    {
        $colors = ['#2563eb', '#dc2626', '#16a34a', '#9333ea', '#ea580c', '#0891b2', '#be185d', '#4f46e5'];

        return ['id' => (int) $user->id, 'name' => (string) $user->name, 'color' => $colors[(int) $user->id % count($colors)]];
    }

    public function relay(int $organizationId, User $user, int $sessionId, array $data): ?array
    {
        $session = $this->access->requireSession($user, $sessionId, $organizationId);
        if ($data['type'] === 'select' && ! DesignModelSessionPayloadValidator::selection($data['payload'], ($data['schema_version'] ?? null) === null)) {
            $this->invalid();
        }
        if ($data['type'] === 'select' && $data['payload']['model_version_id'] !== null
            && ! in_array((int) $data['payload']['model_version_id'], $session->modelSetRevision->version_ids, true)) {
            $this->invalid();
        }
        if ($data['type'] === 'select' && ($data['schema_version'] ?? null) !== null && $data['payload']['element_id'] !== null) {
            $data['payload']['element_id'] = (int) $data['payload']['element_id'];
        }
        $envelope = $this->mutate($session, $user, $data);
        if ($envelope !== null) {
            event(new DesignModelSessionTransientEvent($sessionId, $envelope['type'], $envelope['payload'], $envelope['sender'], $envelope));
        }

        return $envelope;
    }

    public function storeViewState(int $organizationId, User $user, int $sessionId, array $data): ?array
    {
        $session = $this->access->requireSession($user, $sessionId, $organizationId);
        $state = $data['view_state'];
        $ids = array_map('intval', $session->modelSetRevision->version_ids);
        $modelIds = array_map('intval', array_column($state['models'], 'version_id'));
        sort($ids);
        sort($modelIds);
        if ((int) $state['model_set_revision_id'] !== (int) $session->model_set_revision_id || $ids !== $modelIds) {
            $this->invalid();
        }
        foreach ($state['selection'] as $item) {
            if (! in_array((int) $item['version_id'], $ids, true) || ! DesignModelSessionPayloadValidator::elementId($item['element_id'])) {
                $this->invalid();
            }
        }
        $state['schema_version'] = 1;
        $state['model_set_revision_id'] = (string) $session->model_set_revision_id;
        foreach ($state['models'] as &$model) {
            $model['version_id'] = (string) (int) $model['version_id'];
            $model['visible'] = (bool) $model['visible'];
            foreach (['hidden_element_ids', 'isolated_element_ids'] as $key) {
                if (count(array_filter($model[$key], DesignModelSessionPayloadValidator::elementId(...))) !== count($model[$key])) {
                    $this->invalid();
                }
                $model[$key] = array_map('intval', $model[$key]);
            }
        }
        unset($model);
        foreach ($state['selection'] as &$item) {
            $item['version_id'] = (string) (int) $item['version_id'];
            $item['element_id'] = (int) $item['element_id'];
        }
        unset($item);
        foreach ($state['sections'] as &$section) {
            $section['enabled'] = (bool) $section['enabled'];
        }
        unset($section);
        $envelope = $this->mutate($session, $user, [
            'schema_version' => 2, 'type' => 'view', 'client_id' => $data['client_id'],
            'sequence' => $data['sequence'], 'payload' => null,
        ], $state);
        if ($envelope === null) {
            return null;
        }
        event(new DesignModelSessionTransientEvent($sessionId, 'view', $envelope['payload'], $envelope['sender'], $envelope));

        return ['client_id' => $envelope['client_id'], 'model_set_revision_id' => $envelope['model_set_revision_id'],
            'revision' => $envelope['payload']['revision'], 'sequence' => $envelope['sequence'],
            'occurred_at' => $envelope['occurred_at'], 'view_state' => $state];
    }

    public function participants(int $organizationId, User $user, int $sessionId): array
    {
        return $this->snapshot($this->access->requireSession($user, $sessionId, $organizationId));
    }

    public function viewState(int $organizationId, User $user, int $sessionId, string $clientId, ?int $revision = null): ?array
    {
        return $this->cachedView($this->access->requireSession($user, $sessionId, $organizationId), $clientId, $revision);
    }

    public function snapshot(DesignModelSession $session): array
    {
        $cache = $this->cache();
        $clients = $cache->get($this->key($session).':clients', []);
        $participants = [];
        foreach ($clients as $clientId) {
            $state = $cache->get($this->key($session).':client:'.$clientId);
            if (is_array($state) && (int) $state['model_set_revision_id'] === (int) $session->model_set_revision_id) {
                $participants[] = array_intersect_key($state, array_flip([
                    'client_id', 'sender', 'model_set_revision_id', 'last_seen_at', 'latest_events', 'view', 'max_sequence',
                ]));
            }
        }

        return $participants;
    }

    private function mutate(DesignModelSession $session, User $user, array $data, ?array $viewState = null): ?array
    {
        $legacy = ! array_key_exists('schema_version', $data);
        if ($legacy) {
            if (array_key_exists('client_id', $data) || array_key_exists('sequence', $data)) {
                $this->invalid();
            }
            $clientId = 'legacy-'.$user->id;
        } else {
            if (! in_array($data['schema_version'], [2, '2'], true)
                || ! isset($data['client_id'], $data['sequence']) || ! is_string($data['client_id'])
                || strlen($data['client_id']) > 100
                || preg_match('/^(?!legacy-)[A-Za-z0-9_-]+$/D', $data['client_id']) !== 1
                || filter_var($data['sequence'], FILTER_VALIDATE_INT) === false
                || (int) $data['sequence'] < 0 || (int) $data['sequence'] > 9007199254740991) {
                $this->invalid();
            }
            $data['schema_version'] = 2;
            $clientId = $data['client_id'];
        }
        $cache = $this->cache();
        $key = $this->key($session);

        return $cache->lock($key.':lock', 5)->block(2, function () use ($cache, $key, $session, $user, $data, $clientId, $viewState, $legacy): ?array {
            $mutate = function () use ($cache, $key, $session, $user, $data, $clientId, $viewState, $legacy): ?array {
                $stateKey = $key.':client:'.$clientId;
                $type = $data['type'];
                $identity = ['organization_id' => (int) $session->organization_id, 'session_id' => (int) $session->id,
                    'model_set_revision_id' => (int) $session->model_set_revision_id, 'client_id' => $clientId];
                $order = DesignModelSessionEventOrder::query()->where($identity)->lockForUpdate()->first();
                if ($order === null) {
                    DesignModelSessionEventOrder::query()->insertOrIgnore($identity + [
                        'user_id' => (int) $user->id, 'sequences' => '{}', 'max_sequence' => -1, 'leave_sequence' => null,
                    ]);
                    $order = DesignModelSessionEventOrder::query()->where($identity)->lockForUpdate()->firstOrFail();
                }
                if ((int) $order->user_id !== (int) $user->id) {
                    $this->invalid();
                }
                $sequences = $order->sequences ?? [];
                $state = $cache->get($stateKey, []);
                if ($state !== [] && (int) $state['sender']['id'] !== (int) $user->id) {
                    $this->invalid();
                }
                $previous = (int) ($sequences[$type] ?? -1);
                $sequence = $legacy ? max(1, (int) $order->max_sequence + 1) : (int) $data['sequence'];
                if ($sequence <= $previous) {
                    return null;
                }
                if ($type === 'leave'
                    ? $sequence <= (int) $order->max_sequence
                    : ($order->leave_sequence !== null && $sequence <= (int) $order->leave_sequence)) {
                    return null;
                }
                $payload = $data['payload'];
                if ($type === 'view' && $viewState === null && ($state['view']['revision'] ?? null) !== ($payload['revision'] ?? null)) {
                    $this->invalid();
                }
                $occurredAt = now()->toIso8601String();
                $sender = self::sender($user);
                $state['client_id'] = $clientId;
                $state['sender'] = $sender;
                $state['model_set_revision_id'] = (int) $session->model_set_revision_id;
                $state['last_seen_at'] = $occurredAt;
                $state['sequences'][$type] = $sequence;
                $state['max_sequence'] = max((int) $order->max_sequence, $sequence);
                $state['latest_events'] ??= [];
                if ($viewState !== null) {
                    $revision = (int) ($state['view']['revision'] ?? 0) + 1;
                    $payload = ['revision' => $revision];
                    $state['view'] = ['revision' => $revision, 'sequence' => $sequence, 'occurred_at' => $occurredAt];
                    $stored = $cache->put($stateKey.':view:'.$revision, [
                        'client_id' => $clientId, 'model_set_revision_id' => (int) $session->model_set_revision_id,
                        ...$state['view'], 'view_state' => $viewState,
                    ], self::TTL);
                    if (! $stored) {
                        throw new RuntimeException('design_session_view_cache_write_failed');
                    }
                }
                $envelope = [
                    'schema_version' => 2, 'session_id' => (int) $session->id,
                    'model_set_revision_id' => (int) $session->model_set_revision_id, 'sender' => $sender,
                    'client_id' => $clientId, 'sequence' => $sequence, 'type' => $type,
                    'occurred_at' => $occurredAt, 'payload' => $payload,
                ];
                if (in_array($type, ['camera', 'cursor', 'select'], true)) {
                    $state['latest_events'][$type] = $envelope;
                }
                $clients = $cache->get($key.':clients', []);
                $clients = array_values(array_filter($clients, static fn ($id): bool => $cache->has($key.':client:'.$id)));
                if ($type === 'leave') {
                    $cache->forget($stateKey);
                    if (isset($state['view'])) {
                        $cache->forget($stateKey.':view:'.$state['view']['revision']);
                    }
                    $clients = array_values(array_diff($clients, [$clientId]));
                } else {
                    $stored = $cache->put($stateKey, $state, self::TTL);
                    if (! $stored) {
                        throw new RuntimeException('design_session_cache_write_failed');
                    }
                    if ($viewState === null && isset($state['view'])) {
                        $store = $cache->getStore();
                        $viewKey = $stateKey.':view:'.$state['view']['revision'];
                        if ($store instanceof RedisStore) {
                            $store->connection()->expire($store->getPrefix().$viewKey, self::TTL);
                        } else {
                            $cached = $cache->get($viewKey);
                            if (is_array($cached)) {
                                $stored = $cache->put($viewKey, $cached, self::TTL);
                                if (! $stored) {
                                    throw new RuntimeException('design_session_view_cache_write_failed');
                                }
                            }
                        }
                    }
                    $clients = array_values(array_unique([...$clients, $clientId]));
                }
                $stored = $cache->put($key.':clients', $clients, self::TTL);
                if (! $stored) {
                    throw new RuntimeException('design_session_clients_cache_write_failed');
                }
                $sequences[$type] = $sequence;
                $order->sequences = $sequences;
                $order->max_sequence = max((int) $order->max_sequence, $sequence);
                if ($type === 'leave') {
                    $order->leave_sequence = $sequence;
                }
                $order->save();

                return $envelope;
            };

            return DB::transaction($mutate);
        });
    }

    private function cachedView(DesignModelSession $session, string $clientId, ?int $revision): ?array
    {
        $stateKey = $this->key($session).':client:'.$clientId;
        $state = $this->cache()->get($stateKey);
        if (! is_array($state) || ! isset($state['view'])) {
            return null;
        }
        $revision ??= (int) $state['view']['revision'];
        $viewState = $this->cache()->get($stateKey.':view:'.$revision);
        if (! is_array($viewState)) {
            return null;
        }

        return $viewState;
    }

    private function cache(): Repository
    {
        return Cache::store(config('design_management.session_cache_store', app()->runningUnitTests() ? 'array' : 'redis'));
    }

    private function key(DesignModelSession $session): string
    {
        return 'design-model-session:'.$session->organization_id.':'.$session->id.':'.$session->model_set_revision_id;
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['payload' => trans_message('design_bim.errors.event_payload_invalid')]);
    }
}
