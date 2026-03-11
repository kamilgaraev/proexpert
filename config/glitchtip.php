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
    'issue_sync_cache_prefix' => env('GLITCHTIP_ISSUE_SYNC_CACHE_PREFIX', 'glitchtip.issue_sync'),
    'auto_create_github_issue' => (bool) env('GLITCHTIP_AUTO_CREATE_GITHUB_ISSUE', false),
    'github' => [
        'token' => env('GITHUB_ISSUES_TOKEN'),
        'repository' => env('GITHUB_ISSUES_REPOSITORY'),
        'labels' => array_values(array_filter(array_map('trim', explode(',', (string) env('GITHUB_ISSUES_LABELS', 'bug,glitchtip'))))),
    ],
];
