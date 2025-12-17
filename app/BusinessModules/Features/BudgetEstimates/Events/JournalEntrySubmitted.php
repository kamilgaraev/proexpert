<?php

namespace App\BusinessModules\Features\BudgetEstimates\Events;

use App\Models\ConstructionJournalEntry;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class JournalEntrySubmitted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public ConstructionJournalEntry $entry
    ) {}
}

