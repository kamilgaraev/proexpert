<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GlitchTipOrchestratorService
{
    public function validateWebhookSecret(?string $providedSecret): bool
    {
        $expectedSecret = (string) config('glitchtip.webhook_secret', '');
        return $this->matchesSecret($expectedSecret, $providedSecret, (bool) config('glitchtip.allow_unsigned_webhooks', false));
    }

    public function validateInternalToken(?string $providedToken): bool
    {
        $expectedToken = (string) config('glitchtip.internal_token', '');
        return $this->matchesSecret($expectedToken, $providedToken, false);
    }

    public function matchesSecret(string $expectedSecret, ?string $providedSecret, bool $allowUnsignedWebhooks = false): bool
    {
        if ($expectedSecret === '') {
            return $allowUnsignedWebhooks;
        }

        return is_string($providedSecret) && hash_equals($expectedSecret, $providedSecret);
    }

    public function normalizeWebhookPayload(array $payload): array
    {
        $event = Arr::get($payload, 'data', []);
        $tags = $this->normalizeTags(Arr::get($event, 'tags', Arr::get($payload, 'tags', [])));
        $requestData = Arr::get($event, 'request', []);
        $url = Arr::get($requestData, 'url');
        $path = is_string($url) ? (string) parse_url($url, PHP_URL_PATH) : null;
        $exceptionValues = Arr::get($event, 'exception.values', []);
        $frames = $this->extractFrames($exceptionValues);

        $normalized = [
            'received_at' => Carbon::now()->toIso8601String(),
            'issue_id' => Arr::get($payload, 'issue_id')
                ?? Arr::get($payload, 'issue.id')
                ?? Arr::get($event, 'groupID')
                ?? Arr::get($event, 'group_id'),
            'event_id' => Arr::get($payload, 'event_id')
                ?? Arr::get($event, 'event_id')
                ?? Arr::get($event, 'id'),
            'project' => Arr::get($payload, 'project')
                ?? Arr::get($event, 'project')
                ?? config('glitchtip.project'),
            'title' => Arr::get($payload, 'title')
                ?? Arr::get($event, 'title')
                ?? Arr::get($payload, 'message')
                ?? Arr::get($event, 'message')
                ?? 'Unknown error',
            'message' => Arr::get($payload, 'message')
                ?? Arr::get($event, 'message')
                ?? Arr::get($exceptionValues, '0.value'),
            'level' => Arr::get($payload, 'level')
                ?? Arr::get($event, 'level')
                ?? 'error',
            'status' => Arr::get($payload, 'status')
                ?? Arr::get($event, 'status'),
            'environment' => $tags['environment']
                ?? Arr::get($event, 'environment')
                ?? Arr::get($payload, 'environment'),
            'release' => $tags['release']
                ?? Arr::get($event, 'release')
                ?? Arr::get($payload, 'release'),
            'url' => $url,
            'path' => $path,
            'method' => Arr::get($requestData, 'method') ?? $tags['request_method'] ?? null,
            'route_name' => $tags['route_name'] ?? null,
            'culprit' => Arr::get($payload, 'culprit') ?? Arr::get($event, 'culprit'),
            'user_id' => $tags['user_id']
                ?? Arr::get($event, 'user.id')
                ?? Arr::get($payload, 'user.id'),
            'organization_id' => $tags['organization_id']
                ?? Arr::get($payload, 'organization_id'),
            'interface' => $tags['interface'] ?? null,
            'module' => $tags['module'] ?? null,
            'correlation_id' => $tags['correlation_id'] ?? null,
            'count' => Arr::get($payload, 'count') ?? Arr::get($event, 'count'),
            'first_seen' => Arr::get($payload, 'first_seen') ?? Arr::get($payload, 'firstSeen'),
            'last_seen' => Arr::get($payload, 'last_seen') ?? Arr::get($payload, 'lastSeen'),
            'fingerprint' => Arr::get($event, 'fingerprint', Arr::get($payload, 'fingerprint', [])),
            'tags' => $tags,
            'top_frames' => array_slice($frames, 0, 8),
            'raw' => [
                'action' => Arr::get($payload, 'action'),
                'web_url' => Arr::get($payload, 'web_url') ?? Arr::get($payload, 'url'),
            ],
        ];

        return $normalized;
    }

    public function storeLatestIncident(array $incident): void
    {
        Cache::put(
            (string) config('glitchtip.latest_incident_cache_key'),
            $incident,
            now()->addSeconds((int) config('glitchtip.latest_incident_ttl_seconds', 86400))
        );

        Log::channel('technical')->warning('glitchtip.webhook.received', $incident);
    }

    public function getLatestIncident(): ?array
    {
        $incident = Cache::get((string) config('glitchtip.latest_incident_cache_key'));

        return is_array($incident) ? $incident : null;
    }

    public function getStatus(): array
    {
        return [
            'api_configured' => $this->isApiConfigured(),
            'webhook_secret_configured' => config('glitchtip.webhook_secret') !== null && config('glitchtip.webhook_secret') !== '',
            'internal_token_configured' => config('glitchtip.internal_token') !== null && config('glitchtip.internal_token') !== '',
            'allow_unsigned_webhooks' => (bool) config('glitchtip.allow_unsigned_webhooks', false),
            'organization' => config('glitchtip.organization'),
            'project' => config('glitchtip.project'),
            'period' => config('glitchtip.period'),
            'latest_incident' => $this->getLatestIncident(),
        ];
    }

    public function fetchProjectIssues(?int $limit = null): array
    {
        if (!$this->isApiConfigured()) {
            throw new RuntimeException('GlitchTip API is not configured.');
        }

        $normalizedLimit = max(1, min($limit ?? (int) config('glitchtip.issues_limit', 20), 100));

        $response = Http::baseUrl((string) config('glitchtip.url'))
            ->withToken((string) config('glitchtip.token'))
            ->acceptJson()
            ->timeout(20)
            ->get(
                sprintf(
                    '/api/0/projects/%s/%s/issues/',
                    config('glitchtip.organization'),
                    config('glitchtip.project')
                ),
                [
                    'query' => sprintf('is:unresolved issue.priority:[high,medium]'),
                    'statsPeriod' => config('glitchtip.period', '24h'),
                    'limit' => $normalizedLimit,
                ]
            );

        if ($response->failed()) {
            throw new RuntimeException('Unable to fetch issues from GlitchTip API.');
        }

        $issues = $response->json();

        if (!is_array($issues)) {
            return [];
        }

        return $this->summarizeIssues($issues);
    }

    public function summarizeIssues(array $issues): array
    {
        return array_map(function (array $issue): array {
            return [
                'id' => Arr::get($issue, 'id'),
                'short_id' => Arr::get($issue, 'shortId'),
                'title' => Arr::get($issue, 'title'),
                'culprit' => Arr::get($issue, 'culprit'),
                'level' => Arr::get($issue, 'level'),
                'status' => Arr::get($issue, 'status'),
                'count' => Arr::get($issue, 'count'),
                'user_count' => Arr::get($issue, 'userCount'),
                'permalink' => Arr::get($issue, 'permalink'),
                'last_seen' => Arr::get($issue, 'lastSeen'),
                'first_seen' => Arr::get($issue, 'firstSeen'),
            ];
        }, $issues);
    }

    private function isApiConfigured(): bool
    {
        return config('glitchtip.url') !== ''
            && config('glitchtip.token') !== null
            && config('glitchtip.organization') !== null
            && config('glitchtip.project') !== null;
    }

    private function normalizeTags(array $tags): array
    {
        $normalized = [];

        foreach ($tags as $key => $value) {
            if (is_array($value) && array_key_exists('key', $value) && array_key_exists('value', $value)) {
                $normalized[(string) $value['key']] = $value['value'];
                continue;
            }

            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    private function extractFrames(array $exceptionValues): array
    {
        $frames = [];

        foreach ($exceptionValues as $exceptionValue) {
            $frameItems = Arr::get($exceptionValue, 'stacktrace.frames', []);

            foreach ($frameItems as $frame) {
                $frames[] = [
                    'file' => Arr::get($frame, 'filename'),
                    'function' => Arr::get($frame, 'function'),
                    'module' => Arr::get($frame, 'module'),
                    'line' => Arr::get($frame, 'lineno'),
                    'context_line' => Arr::get($frame, 'context_line'),
                ];
            }
        }

        return array_values(array_filter($frames, static fn (array $frame): bool => !empty($frame['file']) || !empty($frame['function'])));
    }
}
