<?php

declare(strict_types=1);

return [
    'marketing_frontend_url' => env('MARKETING_FRONTEND_URL', 'https://prohelper.pro'),
    'platform_content_organization_id' => env('PLATFORM_CONTENT_ORGANIZATION_ID'),
    'preview_ttl_minutes' => (int) env('BLOG_PREVIEW_TTL_MINUTES', 30),
];
