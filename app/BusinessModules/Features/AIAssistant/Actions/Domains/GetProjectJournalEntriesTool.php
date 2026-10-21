<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantProjectJournalEntriesReader;

final readonly class GetProjectJournalEntriesTool extends AssistantProjectOperationalTool
{
    public const NAME = 'get_project_journal_entries';

    public const DESCRIPTION = 'ai_assistant_journal_entries.description';

    public function __construct(AssistantProjectJournalEntriesReader $reader)
    {
        parent::__construct($reader);
    }

    public function getParametersSchema(): array
    {
        $schema = parent::getParametersSchema();
        foreach (['journal_name', 'journal_number'] as $selector) {
            $schema['properties'][$selector] = ['type' => ['string', 'null'], 'minLength' => 1, 'maxLength' => 255,
                'description' => trans_message('ai_assistant_journal_entries.'.$selector)];
        }
        $schema['properties']['offset'] = ['type' => 'integer', 'minimum' => 0, 'maximum' => 9999];

        return $schema;
    }
}
