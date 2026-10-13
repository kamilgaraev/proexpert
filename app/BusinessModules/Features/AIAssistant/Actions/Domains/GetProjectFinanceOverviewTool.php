<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AssistantProjectFinanceOverview;
use App\Models\Organization;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final readonly class GetProjectFinanceOverviewTool implements AIToolInterface
{
    public function __construct(private AssistantProjectFinanceOverview $reader, private AIPermissionChecker $permissions) {}
    public function getName(): string { return 'get_project_finance_overview'; }
    public function getDescription(): string { return trans_message('ai_assistant_decisions.finance_description'); }
    public function getParametersSchema(): array
    {
        return ['type' => 'object', 'properties' => ['project_id' => ['type' => 'integer', 'minimum' => 1],
            'period_start' => ['type' => 'string', 'format' => 'date'], 'period_end' => ['type' => 'string', 'format' => 'date'],
            'currency' => ['type' => ['string', 'null'], 'enum' => ['RUB', 'USD', 'EUR', null]]],
            'required' => ['project_id', 'period_start', 'period_end', 'currency'], 'additionalProperties' => false];
    }
    public function execute(array $arguments, ?User $user, Organization $organization): array|string
    {
        if ($user === null || (int) $user->current_organization_id !== (int) $organization->id
            || ! $this->permissions->canExecuteTool($user, $this->getName(), $arguments)) { throw new AccessDeniedHttpException; }
        return $this->reader->read($user, $organization, $arguments);
    }
}
