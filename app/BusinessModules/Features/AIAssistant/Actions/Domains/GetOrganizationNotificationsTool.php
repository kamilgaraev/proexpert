<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOrganizationNotificationsReader;

final readonly class GetOrganizationNotificationsTool extends AssistantOrganizationOperationalTool
{
    public const NAME = 'get_organization_notifications';
    public const DESCRIPTION = 'ai_assistant_operational.notification_description';
    public function __construct(AssistantOrganizationNotificationsReader $reader) { parent::__construct($reader); }
}
