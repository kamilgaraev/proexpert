<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Access;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;

final class ReportDefinitionModuleAccessDecision
{
    /** @var array<string, bool> */
    private array $accessByModule = [];

    public function __construct(
        private readonly int $organizationId,
        private readonly ReportDefinitionModuleAuthorizer $authorizer,
    ) {}

    public function allows(int $organizationId, ReportDefinition $definition): bool
    {
        return $this->allowsModule($organizationId, $definition->sourceModule);
    }

    public function allowsModule(int $organizationId, string $module): bool
    {
        if ($organizationId !== $this->organizationId || $organizationId <= 0) {
            return false;
        }

        if (! array_key_exists($module, $this->accessByModule)) {
            $this->accessByModule[$module] = $this->authorizer->allowsModule($organizationId, $module);
        }

        return $this->accessByModule[$module];
    }
}
