<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\DTO;

use InvalidArgumentException;

final readonly class ProjectControlSourceRow
{
    public function __construct(
        public string $rowKey,
        public int $projectId,
        public int $taskId,
        public ?string $wbsCode,
        public ?int $contractorId,
        public ?int $costCenterId,
        public ProjectControlAmounts $amounts,
        public array $sourceRefs,
    ) {
        if (trim($rowKey) === ''
            || $projectId < 1
            || $taskId < 1
            || !array_is_list($sourceRefs)
            || $sourceRefs === []
        ) {
            throw new InvalidArgumentException('project_control_source_row_invalid');
        }
    }

    public function canonicalIdentity(): array
    {
        return [
            'amounts' => get_object_vars($this->amounts),
            'contractor_id' => $this->contractorId,
            'cost_center_id' => $this->costCenterId,
            'project_id' => $this->projectId,
            'row_key' => $this->rowKey,
            'source_refs' => $this->sourceRefs,
            'task_id' => $this->taskId,
            'wbs_code' => $this->wbsCode,
        ];
    }
}
