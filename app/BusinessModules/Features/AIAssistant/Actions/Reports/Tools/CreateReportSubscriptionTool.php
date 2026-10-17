<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportSubscriptionManager;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportSubscriptionSchema;
use App\Models\Organization;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final readonly class CreateReportSubscriptionTool implements AIToolInterface
{
    public function __construct(private AssistantReportSubscriptionManager $manager) {}

    public function getName(): string
    {
        return 'create_report_subscription';
    }

    public function getDescription(): string
    {
        return trans_message('ai_report_subscriptions.create_description');
    }

    public function getParametersSchema(): array
    {
        return AssistantReportSubscriptionSchema::create();
    }

    public function execute(array $arguments, ?User $user, Organization $organization): array|string
    {
        if ($user === null) {
            throw new AccessDeniedHttpException;
        }

        return $this->manager->create($arguments, $user, $organization);
    }
}
