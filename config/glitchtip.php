<?php

declare(strict_types=1);

return [
    'url' => rtrim((string) env('GLITCHTIP_URL', ''), '/'),
    'token' => env('GLITCHTIP_TOKEN'),
    'organization' => env('GLITCHTIP_ORG'),
    'project' => env('GLITCHTIP_PROJECT'),
    'period' => env('GLITCHTIP_PERIOD', '24h'),
    'internal_token' => env('GLITCHTIP_INTERNAL_TOKEN'),
    'webhook_secret' => env('GLITCHTIP_WEBHOOK_SECRET'),
    'allow_unsigned_webhooks' => (bool) env('GLITCHTIP_ALLOW_UNSIGNED_WEBHOOKS', false),
    'latest_incident_cache_key' => env('GLITCHTIP_LATEST_INCIDENT_CACHE_KEY', 'glitchtip.latest_incident'),
    'latest_incident_ttl_seconds' => (int) env('GLITCHTIP_LATEST_INCIDENT_TTL_SECONDS', 86400),
    'issues_limit' => (int) env('GLITCHTIP_ISSUES_LIMIT', 20),
];
