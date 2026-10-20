<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantRagIndexStatusReader;

final readonly class GetRagIndexStatusTool extends AssistantOrganizationOperationalTool
{
    public const NAME = 'get_rag_index_status';

    public const DESCRIPTION = 'ai_assistant_index_status.description';

    public function __construct(AssistantRagIndexStatusReader $reader)
    {
        parent::__construct($reader);
    }
}
