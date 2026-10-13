<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Domains;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOrganizationOperationalReader;
use App\Models\Organization;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

abstract readonly class AssistantOrganizationOperationalTool implements AIToolInterface
{
    public const NAME = '';
    public const DESCRIPTION = '';
    public function __construct(private AssistantOrganizationOperationalReader $reader) {}
    public function getName(): string { return static::NAME; }
    public function getDescription(): string { return trans_message(static::DESCRIPTION); }
    public function getParametersSchema(): array
    {
        return ['type' => 'object', 'properties' => ['scope' => ['type' => 'string', 'enum' => ['organization']]], 'required' => ['scope'], 'additionalProperties' => false];
    }
    public function execute(array $arguments, ?User $user, Organization $organization): array|string
    {
        if ($user === null || (int) $user->current_organization_id !== (int) $organization->id) { throw new AccessDeniedHttpException; }
        return $this->reader->read($user, (int) $organization->id, $arguments);
    }
}
