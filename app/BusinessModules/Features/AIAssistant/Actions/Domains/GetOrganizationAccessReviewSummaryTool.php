<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantAccessRecertificationReader;

final readonly class GetOrganizationAccessReviewSummaryTool extends AssistantOrganizationOperationalTool
{
    public const NAME = 'get_organization_access_review_summary';
    public const DESCRIPTION = 'ai_assistant_operational.access_review_description';
    public function __construct(AssistantAccessRecertificationReader $reader) { parent::__construct($reader); }
}
