<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Readiness;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSourceReadinessProbe;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceReadiness;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\DTO\ProjectControlSourceRow;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Services\ProjectControlSourceAssembler;
use App\Support\Reporting\ReportSourceReadinessFactory;
use InvalidArgumentException;

final readonly class ProjectControlReadinessProbe implements ReportSourceReadinessProbe
{
    public function __construct(
        private ReportSourceReadinessFactory $readiness,
        private ProjectControlSourceAssembler $sources,
    ) {}

    public function supports(ReportDefinition $definition): bool
    {
        return $definition->code === 'project_evm_control'
            && $definition->formulaVersion === 'project_control_core.v1';
    }

    public function reportCodes(): array
    {
        return ['project_evm_control'];
    }

    public function inspect(
        ReportExecutionContext $context,
        ReportQuery $query,
    ): ReportSourceReadiness {
        try {
            $source = $this->sources->assemble($context->scope, $query);
        } catch (InvalidArgumentException $exception) {
            return $this->readiness->make(
                [['kind' => 'project_control_source', 'reason' => $exception->getMessage()]],
                [],
                1,
                0,
                'project-control:unavailable',
            );
        }

        $identity = $source['identity'];
        $eligible = [[
            'kind' => 'project_control_identity',
            'project_id' => $identity->projectId,
            'source_hash' => $identity->sourceHash,
        ]];
        $projected = $eligible;
        foreach ($source['rows'] as $row) {
            if (! $row instanceof ProjectControlSourceRow) {
                throw new InvalidArgumentException('project_control_readiness_row_invalid');
            }
            $candidate = [
                'kind' => 'project_control_row',
                'row_key' => $row->rowKey,
                'source_hash' => hash('sha256', CanonicalJson::encode($row->canonicalIdentity())),
            ];
            $eligible[] = $candidate;
            $projected[] = $candidate;
        }
        $watermark = 'project-control:'.hash('sha256', implode(':', [
            $identity->sourceHash,
            $identity->wipVersion,
            $identity->progressWatermark,
            ...array_column($projected, 'source_hash'),
        ]));

        return $this->readiness->make($eligible, $projected, 0, 0, $watermark);
    }
}
