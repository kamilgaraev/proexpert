<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantProjectJournalReader;

final readonly class GetProjectJournalReadinessTool extends AssistantProjectOperationalTool
{
    public const NAME = 'get_project_journal_readiness';
    public const DESCRIPTION = 'ai_assistant_operational.journal_description';
    public function __construct(AssistantProjectJournalReader $reader) { parent::__construct($reader); }
}
