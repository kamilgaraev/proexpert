<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Console\Commands;

use App\BusinessModules\Core\Mdm\Models\MdmDuplicateGroup;
use App\BusinessModules\Core\Mdm\Models\MdmRecord;
use App\BusinessModules\Core\Mdm\Models\MdmRelationship;
use App\BusinessModules\Core\Mdm\Services\MdmRecordService;
use Illuminate\Console\Command;

class MdmInspectCommand extends Command
{
    protected $signature = 'mdm:inspect {organization_id : ID организации}';

    protected $description = 'Показывает состояние MDM по организации';

    public function handle(MdmRecordService $recordService): int
    {
        $organizationId = (int) $this->argument('organization_id');

        $this->line(json_encode([
            'entities' => $recordService->summary($organizationId),
            'records' => MdmRecord::query()->where('organization_id', $organizationId)->count(),
            'duplicates_open' => MdmDuplicateGroup::query()
                ->where('organization_id', $organizationId)
                ->where('status', 'open')
                ->count(),
            'relationships' => MdmRelationship::query()->where('organization_id', $organizationId)->count(),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
