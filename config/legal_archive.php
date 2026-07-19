<?php

declare(strict_types=1);

return [
    'audit_phase_b_cutover_enabled' => (bool) env('LEGAL_ARCHIVE_AUDIT_PHASE_B_CUTOVER_ENABLED', false),
    'audit_phase_a_max_duration_hours' => (int) env('LEGAL_ARCHIVE_AUDIT_PHASE_A_MAX_DURATION_HOURS', 24),
];
