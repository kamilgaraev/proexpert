<?php

declare(strict_types=1);

return [
    'audit_phase_b_cutover_enabled' => (bool) env('LEGAL_ARCHIVE_AUDIT_PHASE_B_CUTOVER_ENABLED', false),
    'audit_phase_a_max_duration_hours' => (int) env('LEGAL_ARCHIVE_AUDIT_PHASE_A_MAX_DURATION_HOURS', 24),
    'audit_phase_b_drain_ttl_minutes' => (int) env('LEGAL_ARCHIVE_AUDIT_PHASE_B_DRAIN_TTL_MINUTES', 15),
    'audit_writer_secret' => (string) env('LEGAL_ARCHIVE_AUDIT_WRITER_SECRET', ''),
];
