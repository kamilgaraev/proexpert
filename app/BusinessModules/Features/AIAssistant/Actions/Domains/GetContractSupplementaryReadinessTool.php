<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantContractSupplementaryReader;
use App\Models\Organization;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final readonly class GetContractSupplementaryReadinessTool implements AIToolInterface
{
    public function __construct(private AssistantContractSupplementaryReader $reader) {}
    public function getName(): string { return 'get_contract_supplementary_readiness'; }
    public function getDescription(): string { return trans_message('ai_assistant_operational.supplement_description'); }
    public function getParametersSchema(): array
    {
        return ['type' => 'object', 'properties' => ['contract_number' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
            'project_id' => ['type' => ['integer', 'null'], 'minimum' => 1], 'project_query' => ['type' => ['string', 'null'], 'minLength' => 1, 'maxLength' => 100]],
            'required' => ['contract_number', 'project_id', 'project_query'], 'additionalProperties' => false];
    }
    public function execute(array $arguments, ?User $user, Organization $organization): array|string
    {
        if ($user === null || (int) $user->current_organization_id !== (int) $organization->id) { throw new AccessDeniedHttpException; }
        return $this->reader->read($user, (int) $organization->id, $arguments);
    }
}
