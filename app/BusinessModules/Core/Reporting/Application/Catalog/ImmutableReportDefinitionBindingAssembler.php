<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Catalog;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionBindingAssembler;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBindingMap;
use LogicException;

final class ImmutableReportDefinitionBindingAssembler implements ReportDefinitionBindingAssembler
{
    private array $bindings = [];

    private bool $frozen = false;

    public function __construct(
        private ReportBindingCompatibilityChecker $compatibility,
        private ReportCodeSetComparator $codes,
    ) {}

    public function register(ReportDefinitionBinding $binding): void
    {
        if ($this->frozen || array_key_exists($binding->code, $this->bindings)) {
            throw new LogicException('binding_registration_closed');
        }

        $this->bindings[$binding->code] = $binding;
    }

    public function assemble(
        ReportDefinitionRegistry $registry,
    ): ReportDefinitionBindingMap {
        if ($this->frozen) {
            throw new LogicException('binding_assembly_closed');
        }

        $publishedCodes = $this->codes->validate(
            $registry->publishedCodes(),
            'published_code',
        );
        $registeredCodes = $this->codes->validate(
            array_keys($this->bindings),
            'registered_binding_code',
        );
        if (! $this->codes->equal($publishedCodes, $registeredCodes)) {
            throw new LogicException('published_binding_set_mismatch');
        }

        $resolved = [];
        foreach ($publishedCodes as $code) {
            $published = $registry->published($code);
            if (! hash_equals($code, $published->code)) {
                throw new LogicException('published_registry_identity_mismatch');
            }
            $binding = $this->bindings[$code];
            $this->compatibility->runtime($published, $binding);
            $resolved[$code] = $binding;
        }

        $this->frozen = true;

        return new ReportDefinitionBindingMap($resolved);
    }
}
