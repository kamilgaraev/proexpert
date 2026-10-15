<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantAdvanceSettingsReader;

final readonly class GetOrganizationAdvanceSettingsTool extends AssistantOrganizationOperationalTool
{
    public const NAME = 'get_organization_advance_settings';
    public const DESCRIPTION = 'ai_assistant_operational.advance_settings_description';
    public function __construct(AssistantAdvanceSettingsReader $reader) { parent::__construct($reader); }
}
