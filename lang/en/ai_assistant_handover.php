<?php

declare(strict_types=1);

return [
    'description' => 'Project acceptance and handover summary: complete authorized status counts, up to 20 examples, native readiness blockers and next steps. Read only.',
    'scope' => 'Acceptance and handover for “:project” as of :date.',
    'total' => 'Authorized scopes: :total. Detailed examples: :shown (up to 20).',
    'status_planned' => 'Planned', 'status_in_progress' => 'Acceptance in progress', 'status_findings_open' => 'Open findings',
    'status_ready_for_reinspection' => 'Ready for reinspection', 'status_accepted' => 'Accepted, not handed over',
    'status_handed_over' => 'Handed over', 'status_reopened' => 'Reopened', 'status_rejected' => 'Rejected', 'status_unknown' => 'Status requires review',
    'readiness' => 'Technical acceptance checks: :acceptance. Handover checks: :handover.',
    'ready' => 'passed', 'blocked' => 'blocked',
    'blocker' => 'Blocker: :message. Acceptance: :acceptance; handover: :handover.',
    'blocker_required_checklist_incomplete' => 'required checklist items are not all accepted',
    'blocker_open_findings_block_accept' => 'findings remain open', 'blocker_work_rework_open' => 'rework is not accepted',
    'blocker_quantity_source_changed' => 'presented work evidence changed or is unconfirmed',
    'blocker_executive_evidence_invalid' => 'executive documentation set does not match the scope',
    'blocker_required_documents_block_handover' => 'handover package is absent or required documents are not ready',
    'blocker_requirements_not_configured' => 'documentation requirements are not configured',
    'blocker_approved_list_missing' => 'approved documentation list is missing', 'blocker_approved_list_mismatch' => 'set does not match the approved list',
    'blocker_applicability_unresolved' => 'documentation requirement applicability is unresolved',
    'blocker_unknown' => 'native readiness check found a blocker; see the scope card',
    'limit' => 'Readiness is unknown: related records exceed the safe reading limit. Review the scope card.',
    'action_done' => 'Next step: handover is recorded; consider reopening if new findings appear.',
    'action_start' => 'Next step: review the scope and start acceptance in its card.',
    'action_findings' => 'Next step: resolve findings and request reinspection.',
    'action_accept' => 'Next step: inspect with participants and record acceptance in the scope card.',
    'action_unblock_acceptance' => 'Next step: resolve the listed blockers and repeat checks before acceptance.',
    'action_handover' => 'Next step: verify package contents and recipient, then record handover in the scope card.',
    'action_unblock_handover' => 'Next step: prepare the handover package, resolve blockers and repeat checks.',
    'action_unknown' => 'Next step: review the scope status in its card.',
    'basis' => 'Status counts cover all authorized project scopes. Native readiness checks cover shown scopes only. Recommendations do not perform acceptance or handover and do not establish action permissions.',
];
