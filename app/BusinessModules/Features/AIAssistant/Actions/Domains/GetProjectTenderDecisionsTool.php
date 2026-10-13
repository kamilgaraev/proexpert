<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantTenderReader;
use App\Models\Organization;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final readonly class GetProjectTenderDecisionsTool implements AIToolInterface
{
    public const NAME = 'get_project_tender_decisions';
    public const DESCRIPTION = 'ai_assistant_operational.tender_description';
    public function __construct(private AssistantTenderReader $reader) {}
    public function getName(): string { return self::NAME; }
    public function getDescription(): string { return trans_message(self::DESCRIPTION); }
    public function getParametersSchema(): array
    {
        return ['type' => 'object', 'properties' => ['project_id' => ['type' => ['integer', 'null'], 'minimum' => 1]], 'required' => ['project_id'], 'additionalProperties' => false];
    }
    public function execute(array $arguments, ?User $user, Organization $organization): array|string
    {
        if ($user === null || (int) $user->current_organization_id !== (int) $organization->id) { throw new AccessDeniedHttpException; }
        return $this->reader->read($user, (int) $organization->id, $arguments);
    }
}
