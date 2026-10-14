<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantNotificationPreferencesReader;

final readonly class GetOwnNotificationPreferencesTool extends AssistantOrganizationOperationalTool
{
    public const NAME = 'get_own_notification_preferences';

    public const DESCRIPTION = 'ai_assistant_operational.notification_preferences_description';

    public function __construct(AssistantNotificationPreferencesReader $reader)
    {
        parent::__construct($reader);
    }
}
