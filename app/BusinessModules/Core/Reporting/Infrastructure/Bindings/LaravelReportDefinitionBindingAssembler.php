<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Bindings;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionBindingAssembler;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBindingMap;
use InvalidArgumentException;

final class LaravelReportDefinitionBindingAssembler implements ReportDefinitionBindingAssembler
{
    private array $bindings = [];

    public function register(ReportDefinitionBinding $binding): void
    {
        if (isset($this->bindings[$binding->code])) {
            $existing = $this->bindings[$binding->code];
            if ($existing->definitionHash->value === $binding->definitionHash->value
                && $existing->contractVersion === $binding->contractVersion
                && $existing->dataProvider::class === $binding->dataProvider::class
                && $existing->rowQuery::class === $binding->rowQuery::class
                && $existing->drillDownProvider::class === $binding->drillDownProvider::class
                && get_debug_type($existing->readinessProbe) === get_debug_type($binding->readinessProbe)
            ) {
                return;
            }
            throw new InvalidArgumentException('report_definition_binding_duplicate');
        }
        $this->bindings[$binding->code] = $binding;
    }

    public function assemble(ReportDefinitionRegistry $registry): ReportDefinitionBindingMap
    {
        $published = array_fill_keys($registry->publishedCodes(), true);
        $bindings = [];
        foreach ($this->bindings as $code => $binding) {
            if (! isset($published[$code])) {
                continue;
            }
            $definition = $registry->published($code)->payload();
            if (! hash_equals($definition->definitionHash->value, $binding->definitionHash->value)
                || $definition->contractVersion !== $binding->contractVersion
            ) {
                throw new InvalidArgumentException('report_definition_binding_identity_mismatch');
            }
            $bindings[$code] = $binding;
        }

        return new ReportDefinitionBindingMap($bindings);
    }
}
