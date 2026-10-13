<?php

declare(strict_types=1);

return [
    'deadlines' => 'Recorded end date in the past: :overdue; date from today through :date inclusive: :soon; no recorded end date: :undated.',
    'renewal' => 'Before renewal, check the current end date and status in the contract record and the required approval and amendment workflow. Contracts were not changed.',
    'description' => 'Active project contracts in the native organization register, including authorized shared and multiproject contracts. Active legal status and active placement only. Complete counts before examples, numbers, end dates and authorized counterparty names. Excludes money aggregates, drafts and archived placements.',
    'scope' => 'Project :project active contracts on :date.',
    'count' => 'Total in the authorized active register: :total. Shown :shown.',
    'row' => 'counterparty: :counterparty; planned end: :end.',
    'self_execution' => 'self-performed work', 'counterparty_unknown' => 'absent or unavailable in this read scope', 'no_date' => 'not specified',
    'overdue' => 'Planned end has passed while the contract remains active. Clarify execution and documented deadline changes at the meeting.',
    'limit' => 'Over 10000 active contracts. No complete total is inferred from a partial sample.',
    'basis' => 'Draft, completed, on-hold, terminated and archived placements excluded. Active does not imply no overdue date. Clarify missing dates in the contract record. Shared-contract counterparty names may be unavailable.',
];
