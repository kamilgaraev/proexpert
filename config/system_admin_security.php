<?php

declare(strict_types=1);

return [
    'session_generation' => env('SYSTEM_ADMIN_SESSION_GENERATION', '2026-05-27-session-hardening'),
    'session_rotation_minutes' => (int) env('SYSTEM_ADMIN_SESSION_ROTATION_MINUTES', 15),
];
