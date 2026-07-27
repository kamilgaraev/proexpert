<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use InvalidArgumentException;

final readonly class ReportDefinitionBindingMap
{
    public array $bindings;

    public function __construct(array $bindings)
    {
        if ($bindings !== [] && array_is_list($bindings)) {
            throw new InvalidArgumentException('report_definition_binding_map_invalid');
        }

        foreach ($bindings as $code => $binding) {
            if (!is_string($code) || preg_match('/^[a-z][a-z0-9_]{2,63}$/', $code) !== 1 || !$binding instanceof ReportDefinitionBinding || $binding->code !== $code) {
                throw new InvalidArgumentException('report_definition_binding_map_invalid');
            }
        }

        ksort($bindings, SORT_STRING);
        $this->bindings = $bindings;
    }

    public function get(string $code): ReportDefinitionBinding
    {
        if (!isset($this->bindings[$code])) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
        }

        return $this->bindings[$code];
    }

    public function all(): array
    {
        return $this->bindings;
    }
}
